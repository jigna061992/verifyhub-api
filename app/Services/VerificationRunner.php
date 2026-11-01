<?php

namespace App\Services;

use App\Models\Retailer;
use App\Models\RetailerPricing;
use App\Models\Verification;
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
        $custom = RetailerPricing::where('retailer_id', $retailer->id)
            ->where('service_id', $service->id)
            ->value('custom_price');

        return round((float) ($custom ?? $service->default_price), 2);
    }

    public function run(Retailer $retailer, VerificationService $service, array $input): Verification
    {
        $price = $this->priceFor($retailer, $service);

        // 1. Create the record and hold the money in one transaction
        $verification = DB::transaction(function () use ($retailer, $service, $input, $price) {
            $v = Verification::create([
                'retailer_id'   => $retailer->id,
                'service_id'    => $service->id,
                'reference'     => (string) Str::uuid(),
                'status'        => 'pending',
                'input_masked'  => $this->mask($service->code, $input),
                'price'         => $price,
                'provider_cost' => $service->provider_cost,
            ]);

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
            $result = ProviderResult::failed('Verification service is not responding. Please try again. You were not charged.');
        }

        // 3. Settle the money and save the result
        DB::transaction(function () use ($verification, $retailer, $service, $result, $price) {
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
                    'result'       => $result->data,
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
