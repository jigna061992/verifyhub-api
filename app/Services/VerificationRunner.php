<?php

namespace App\Services;

use App\Models\Retailer;
use App\Models\RetailerPricing;
use App\Models\Verification;
use App\Models\VerificationConsent;
use App\Models\VerificationService;
use App\Services\Providers\ProviderResult;
use App\Services\Providers\VerificationProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One verification from start to end:
 *  1. reserve the price in the wallet (fails if balance is low, nothing is created)
 *  2. call the provider
 *  3. success -> debit the reserved money | failure -> release it back
 */
class VerificationRunner
{
    public function __construct(private WalletService $wallets)
    {
    }

    // Retailer custom price if set, otherwise the default price. The server always decides the price.
    public function priceFor(Retailer $retailer, VerificationService $service): float
    {
        // Complete KYC is never priced by hand: it is the sum of its 4 services for this retailer
        $bundle = app(BundlePricing::class);

        if ($bundle->isBundle($service)) {
            return $bundle->priceFor($retailer);
        }

        $custom = RetailerPricing::where('retailer_id', $retailer->id)
            ->where('service_id', $service->id)
            ->value('custom_price');

        return round((float) ($custom ?? $service->default_price), 2);
    }

    /**
     * @param array $options optional: customer_name (name match + shown in history), consent [text, version, purpose, user_id, ip, user_agent]
     */
    public function run(Retailer $retailer, VerificationService $service, array $input, array $options = []): Verification
    {
        $price = $this->priceFor($retailer, $service);

        // 1. Create the record and hold the money in one transaction
        $verification = DB::transaction(function () use ($retailer, $service, $input, $price, $options) {
            $v = Verification::create([
                'retailer_id'   => $retailer->id,
                'service_id'    => $service->id,
                'reference'     => (string) Str::uuid(),
                'status'        => 'pending',
                'input_masked'  => $this->mask($service->code, $input),
                'meta'          => ! empty($options['customer_name']) ? ['customer_name' => $options['customer_name']] : null,
                'price'         => $price,
                'provider_cost' => $service->provider_cost,
            ]);

            // The consent the retailer ticked is logged with the check (same table as Aadhaar / e-Sign)
            if (! empty($options['consent'])) {
                $c = $options['consent'];

                VerificationConsent::create([
                    'verification_id' => $v->id,
                    'type'            => 'collection',
                    'purpose'         => $c['purpose'],
                    'text_version'    => (string) $c['version'],
                    'text_hash'       => hash('sha256', $c['text']),
                    'text'            => $c['text'],
                    'channel'         => 'retailer_assisted',
                    'accepted_by'     => $c['user_id'],
                    'ip_address'      => $c['ip'],
                    'user_agent'      => $c['user_agent'],
                    'accepted_at'     => now(),
                ]);
            }

            if ($price > 0) {
                $this->wallets->reserve(
                    $retailer->id,
                    $price,
                    WalletService::verificationReference($v->id, 'reserve'),
                    "{$service->name} (amount on hold)"
                );
            }

            return $v;
        });

        // 2. Call the provider (outside any transaction, it can be slow)
        try {
            $result = $this->provider()->verify($service->code, $input);
        } catch (Throwable $e) {
            report($e);

            // The provider may or may not have processed the request. Do not guess: keep the money on hold
            // and let an admin reconcile it (Admin > Reconciliation). Free checks have nothing to hold.
            if ($price > 0 && config('verification.reconciliation_enabled', true)) {
                return app(ReconciliationService::class)->hold($verification, 'Provider call failed (' . class_basename($e) . ')');
            }

            $result = ProviderResult::failed('Verification service is not responding. Please try again. You were not charged.');
        }

        // 3. Settle the money and save the result
        DB::transaction(function () use ($verification, $retailer, $service, $result, $price, $options) {
            if ($result->success) {
                if ($price > 0) {
                    $this->wallets->debit(
                        $retailer->id,
                        $price,
                        WalletService::verificationReference($verification->id, 'debit'),
                        "{$service->name} charge"
                    );
                }

                $verification->update([
                    'status'       => 'success',
                    'result'       => $this->withNameMatch($service->code, $result->data, $options['customer_name'] ?? null),
                    'provider_ref' => $result->providerRef,
                ]);
            } else {
                if ($price > 0) {
                    $this->wallets->release(
                        $retailer->id,
                        $price,
                        WalletService::verificationReference($verification->id, 'release'),
                        "{$service->name} failed, amount returned"
                    );
                }

                $verification->update([
                    'status'         => 'failed',
                    'failure_reason' => $result->error,
                    'provider_ref'   => $result->providerRef,
                ]);
            }
        });

        return $verification->fresh();
    }

    // When the retailer typed the customer name, compare it with the name the provider returned.
    // This is only extra information: a mismatch does not fail the check or change the charge.
    private function withNameMatch(string $code, array $data, ?string $given): array
    {
        $given = trim((string) $given);
        $field = match ($code) {
            'PAN'  => 'name',
            'BANK' => 'account_holder',
            'GST'  => 'legal_name',
            default => null,
        };

        if ($given === '' || ! $field || empty($data[$field])) {
            return $data;
        }

        $score = NameMatcher::score($given, (string) $data[$field]);

        return $data + [
            'name_match_score' => $score,
            'name_match'       => $score >= (int) config('kyc.name_match.' . strtolower($code), 80) ? 'match' : 'mismatch',
        ];
    }

    private function provider(): VerificationProvider
    {
        $class = config('verification.drivers.' . config('verification.driver'));

        if (! $class) {
            throw new \RuntimeException('Verification provider is not configured.');
        }

        return app($class);
    }

    // Only the last 4 characters stay visible. The full number is never stored.
    private function mask(string $code, array $input): string
    {
        $value = match ($code) {
            'PAN'  => $input['pan'],
            'BANK' => $input['account_number'],
            'GST'  => $input['gstin'],
            default => '',
        };

        $len = strlen($value);

        return $len <= 4 ? str_repeat('X', $len) : str_repeat('X', $len - 4) . substr($value, -4);
    }
}
