<?php

namespace App\Services;

use App\Exceptions\KycException;
use App\Models\KycCase;
use App\Models\KycConsent;
use App\Models\KycStep;
use App\Models\Retailer;
use App\Models\User;
use App\Models\VerificationService;
use App\Models\Wallet;
use App\Services\Kyc\KycProvider;
use App\Services\Providers\ProviderResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Complete KYC: Aadhaar OTP -> PAN -> Bank -> e-Sign, in this fixed order.
 *
 * Money (one bundle price):
 *  - start      : the whole price is reserved in the wallet (fails if the balance is low, nothing is created)
 *  - completed  : reserved money is debited (the retailer is charged once)
 *  - failed / cancelled / expired : reserved money is released back
 *  Each wallet change has a fixed reference, so repeating a call can never charge or refund twice.
 *
 * Safety rules:
 *  - the full Aadhaar number and OTP are only passed to the provider, never saved or logged
 *  - a step can run only after the earlier steps passed, and only one request can run a step at a time
 *  - every step allows a limited number of failed tries (config kyc.max_attempts)
 */
class KycService
{
    public const STEPS = ['aadhaar', 'pan', 'bank', 'esign'];

    // Test mode only: the OTP that the mock provider just made, so the screen can show it once.
    // Lives only inside this request. It is never saved in the database.
    private ?string $devOtp = null;

    public const LABELS = [
        'aadhaar' => 'Aadhaar OTP',
        'pan'     => 'PAN',
        'bank'    => 'Bank account',
        'esign'   => 'e-Sign consent',
    ];

    public function __construct(private WalletService $wallets, private VerificationRunner $runner)
    {
    }

    public static function reference(int $caseId, string $action): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "verifyhub:kyc:{$caseId}:{$action}")->toString();
    }

    /* ------------------------------------------------------------------
     | Price and start
     * ------------------------------------------------------------------ */

    public function bundleService(): ?VerificationService
    {
        return VerificationService::where('code', config('kyc.service_code'))->where('enabled', true)->first();
    }

    // Retailer custom price if set, otherwise the default price. null = service is switched off.
    public function priceFor(Retailer $retailer): ?float
    {
        $service = $this->bundleService();

        return $service ? $this->runner->priceFor($retailer, $service) : null;
    }

    /**
     * Create the case, the 4 steps and the consent log row, and hold the price in the wallet.
     * Throws InsufficientBalanceException when the wallet is low (nothing is saved then).
     *
     * @param array $data customer_name, customer_mobile, purpose
     */
    public function start(Retailer $retailer, User $user, array $data, Request $request): KycCase
    {
        $service = $this->bundleService();

        if (! $service) {
            throw new KycException('Complete KYC is not available right now.', 422, 'service_unavailable');
        }

        $price        = $this->runner->priceFor($retailer, $service);
        $purposeLabel = config('kyc.purposes')[$data['purpose']];
        $text         = $this->consentText($data['customer_name'], $retailer->business_name, $purposeLabel);

        return DB::transaction(function () use ($retailer, $user, $data, $request, $service, $price, $text) {
            $case = KycCase::create([
                'reference'             => (string) Str::uuid(),
                'retailer_id'           => $retailer->id,
                'service_id'            => $service->id,
                'status'                => 'in_progress',
                'current_step'          => self::STEPS[0],
                'customer_name'         => $data['customer_name'],
                'customer_mobile'       => $data['customer_mobile'],
                'customer_mobile_last4' => substr($data['customer_mobile'], -4),
                'price'                 => $price,
                'provider_cost'         => $service->provider_cost,
                'expires_at'            => now()->addMinutes((int) config('kyc.case_ttl_minutes')),
            ]);

            foreach (self::STEPS as $step) {
                KycStep::create(['case_id' => $case->id, 'step' => $step]);
            }

            // Consent log: the customer agreed (confirmed by the retailer) before any check is made
            KycConsent::create([
                'case_id'      => $case->id,
                'type'         => 'collection',
                'purpose'      => $data['purpose'],
                'text_version' => config('kyc.consent.version'),
                'text_hash'    => hash('sha256', $text),
                'text'         => $text,
                'channel'      => 'retailer_assisted',
                'accepted_by'  => $user->id,
                'ip_address'   => $request->ip(),
                'user_agent'   => Str::limit((string) $request->userAgent(), 250, ''),
                'accepted_at'  => now(),
            ]);

            if ($price > 0) {
                $this->wallets->reserve(
                    $retailer->id,
                    $price,
                    self::reference($case->id, 'reserve'),
                    'Complete KYC (amount on hold)'
                );
            }

            return $case;
        });
    }

    /* ------------------------------------------------------------------
     | Step 1: Aadhaar OTP
     * ------------------------------------------------------------------ */

    public function sendAadhaarOtp(KycCase $case, string $aadhaar): KycCase
    {
        $from = ['pending', 'awaiting', 'failed'];

        [$step, $result] = $this->attempt(
            $case,
            'aadhaar',
            $from,
            fn () => $this->provider()->aadhaarSendOtp($aadhaar),
            function (KycStep $step) {
                if ($step->otp_sends >= (int) config('kyc.otp_max_sends')) {
                    throw new KycException('OTP limit reached for this KYC. Please cancel it and start a new KYC.', 429, 'otp_limit');
                }

                $wait = $this->cooldownLeft($step);
                if ($wait > 0) {
                    throw new KycException("Please wait {$wait} seconds before requesting a new OTP.", 429, 'otp_cooldown');
                }
            }
        );

        if ($result->success) {
            if ($this->isDevMode()) {
                $this->devOtp = isset($result->data['dev_otp']) ? (string) $result->data['dev_otp'] : null;
            }

            // Only the last 4 digits are kept. The full number is not stored anywhere.
            $step->update([
                'status'         => 'awaiting',
                'provider_ref'   => $result->providerRef,
                'otp_sends'      => $step->otp_sends + 1,
                'otp_sent_at'    => now(),
                'input_masked'   => 'XXXXXXXX' . substr($aadhaar, -4),
                'failure_reason' => null,
            ]);
        } else {
            $this->failStep($case, $step, $result->error ?? 'Could not send the OTP.', 'failed');
        }

        return $this->reload($case);
    }

    public function verifyAadhaarOtp(KycCase $case, string $otp): KycCase
    {
        [$step, $result] = $this->attempt(
            $case,
            'aadhaar',
            ['awaiting'],
            fn (KycStep $s) => $this->provider()->aadhaarVerifyOtp((string) $s->provider_ref, $otp)
        );

        if ($result->success) {
            $this->passStep($case, $step, [
                'name'          => (string) ($result->data['name'] ?? ''),
                'aadhaar_last4' => substr((string) $step->input_masked, -4),
            ]);
        } else {
            // Wrong OTP: the customer can type it again (the step stays open) until the tries are used up
            $this->failStep($case, $step, $result->error ?? 'Incorrect OTP.', 'awaiting');
        }

        return $this->reload($case);
    }

    /* ------------------------------------------------------------------
     | Step 2: PAN
     * ------------------------------------------------------------------ */

    public function verifyPan(KycCase $case, string $pan): KycCase
    {
        [$step, $result] = $this->attempt($case, 'pan', ['pending', 'failed'], fn () => $this->provider()->pan($pan));

        $masked = $this->mask($pan);

        if (! $result->success) {
            $this->failStep($case, $step, $result->error ?? 'PAN could not be verified.', 'failed', $masked);

            return $this->reload($case);
        }

        $score = NameMatcher::score($this->aadhaarName($case), (string) ($result->data['name'] ?? ''));

        if (config('kyc.require_pan_aadhaar_link') && empty($result->data['aadhaar_linked'])) {
            $this->failStep($case, $step, 'This PAN is not linked with Aadhaar.', 'failed', $masked);
        } elseif ($score < (int) config('kyc.name_match.pan')) {
            // The other name is not shown, so one document cannot be used to read the other
            $this->failStep($case, $step, 'The name on the PAN does not match the name on Aadhaar.', 'failed', $masked);
        } else {
            $this->passStep($case, $step, [
                'name'             => (string) $result->data['name'],
                'pan_status'       => (string) ($result->data['pan_status'] ?? 'VALID'),
                'aadhaar_linked'   => (bool) ($result->data['aadhaar_linked'] ?? false),
                'name_match_score' => $score,
            ], $masked, $result->providerRef);
        }

        return $this->reload($case);
    }

    /* ------------------------------------------------------------------
     | Step 3: Bank account (penny drop)
     * ------------------------------------------------------------------ */

    public function verifyBank(KycCase $case, string $accountNumber, string $ifsc): KycCase
    {
        [$step, $result] = $this->attempt(
            $case,
            'bank',
            ['pending', 'failed'],
            fn () => $this->provider()->bank($accountNumber, $ifsc)
        );

        $masked = $this->mask($accountNumber) . ' / ' . $ifsc;

        if (! $result->success) {
            $this->failStep($case, $step, $result->error ?? 'Bank account could not be verified.', 'failed', $masked);

            return $this->reload($case);
        }

        $score = NameMatcher::score($this->aadhaarName($case), (string) ($result->data['account_holder'] ?? ''));

        if ($score < (int) config('kyc.name_match.bank')) {
            $this->failStep($case, $step, 'The account holder name does not match the name on Aadhaar.', 'failed', $masked);
        } else {
            $this->passStep($case, $step, [
                'account_holder'   => (string) $result->data['account_holder'],
                'account_status'   => (string) ($result->data['account_status'] ?? 'ACTIVE'),
                'name_match_score' => $score,
            ], $masked, $result->providerRef);
        }

        return $this->reload($case);
    }

    /* ------------------------------------------------------------------
     | Step 4: e-Sign of the consent letter
     * ------------------------------------------------------------------ */

    public function startEsign(KycCase $case): KycCase
    {
        $this->ensureOpen($case);

        // Already started (for example the page was refreshed): do not start a second signing request
        $existing = $case->steps()->where('step', 'esign')->first();
        if ($existing && $existing->status === 'awaiting' && $case->current_step === 'esign') {
            return $this->reload($case);
        }

        $letter = $this->letterText($case);
        $hash   = hash('sha256', $letter);

        [$step, $result] = $this->attempt($case, 'esign', ['pending', 'failed'], fn () => $this->provider()->esignStart([
            'reference'       => $case->reference,
            'customer_name'   => $case->customer_name,
            'customer_mobile' => $case->customer_mobile,
            'letter_text'     => $letter,
            'letter_hash'     => $hash,
        ]));

        if ($result->success) {
            $step->update([
                'status'         => 'awaiting',
                'provider_ref'   => $result->providerRef,
                'failure_reason' => null,
                'meta'           => [
                    'sign_url'    => $result->data['sign_url'] ?? null,
                    'letter_text' => $letter,
                    'letter_hash' => $hash,
                ],
            ]);
        } else {
            $this->failStep($case, $step, $result->error ?? 'Could not start e-Sign.', 'failed');
        }

        return $this->reload($case);
    }

    /**
     * The customer signed, declined or the request expired. Called by the provider webhook
     * (after its signature is checked) and by the development simulator. Safe to call twice.
     */
    public function handleEsignResult(string $requestId, string $outcome, ?string $documentRef = null): void
    {
        $step = KycStep::where('step', 'esign')->where('provider_ref', $requestId)->first();

        if (! $step) {
            throw new KycException('Unknown signing request.', 404, 'unknown_request');
        }

        $case = KycCase::findOrFail($step->case_id);

        // Duplicate webhook, or the case was already closed (expired, cancelled): nothing to do
        if ($step->status !== 'awaiting' || $case->status !== 'in_progress') {
            return;
        }

        if ($outcome !== 'signed') {
            $reason = $outcome === 'declined' ? 'The customer declined to sign.' : 'The signing request expired.';
            $this->failStep($case, $step, $reason, 'failed');

            return;
        }

        DB::transaction(function () use ($case, $step, $documentRef, $requestId) {
            // Only one caller can move the step from "awaiting" to "success"
            $claimed = KycStep::where('id', $step->id)->where('status', 'awaiting')->update(['status' => 'processing']);

            if (! $claimed) {
                return;
            }

            $step->refresh();
            $meta = $step->meta ?? [];
            $consent = $case->consents()->where('type', 'collection')->first();

            // Consent log: the signed letter
            KycConsent::create([
                'case_id'      => $case->id,
                'type'         => 'signed_letter',
                'purpose'      => $consent?->purpose ?? 'general_kyc',
                'text_version' => config('kyc.consent.version'),
                'text_hash'    => (string) ($meta['letter_hash'] ?? ''),
                'text'         => (string) ($meta['letter_text'] ?? ''),
                'channel'      => 'esign',
                'provider_ref' => $requestId,
                'accepted_at'  => now(),
            ]);

            $this->passStep($case, $step, [
                'signed_doc_ref' => $documentRef,
                'signed_at'      => now()->toIso8601String(),
            ]);
        });
    }

    /* ------------------------------------------------------------------
     | Close, cancel, expire
     * ------------------------------------------------------------------ */

    public function cancel(KycCase $case): KycCase
    {
        $this->ensureOpen($case);
        $this->close($case, 'cancelled', 'Cancelled by the retailer. Amount returned to your wallet.');

        return $this->reload($case);
    }

    // Closes an open case and returns the held money. Returns false if it was already closed.
    public function close(KycCase $case, string $status, string $reason): bool
    {
        return DB::transaction(function () use ($case, $status, $reason) {
            $closed = KycCase::where('id', $case->id)
                ->where('status', 'in_progress')
                ->update([
                    'status'         => $status,
                    'failure_reason' => Str::limit($reason, 250, ''),
                    'closed_at'      => now(),
                ]);

            if (! $closed) {
                return false;
            }

            if ((float) $case->price > 0) {
                $this->wallets->release(
                    $case->retailer_id,
                    (float) $case->price,
                    self::reference($case->id, 'release'),
                    "Complete KYC {$status}, amount returned"
                );
            }

            return true;
        });
    }

    // Expires the case if its time is over (used when a case is opened and by the scheduled command)
    public function expireIfDue(KycCase $case): KycCase
    {
        if ($case->status === 'in_progress' && $case->expires_at->isPast()) {
            $this->close($case, 'expired', 'This KYC session expired. Amount returned to your wallet.');
        }

        return $this->reload($case);
    }

    /* ------------------------------------------------------------------
     | Response shape for the frontend
     * ------------------------------------------------------------------ */

    public function present(KycCase $case): array
    {
        $case->loadMissing(['steps', 'consents']);

        $maxAttempts = (int) config('kyc.max_attempts');
        $maxSends    = (int) config('kyc.otp_max_sends');

        $steps = $case->steps->map(fn (KycStep $s) => [
            'step'           => $s->step,
            'label'          => self::LABELS[$s->step],
            'status'         => $s->status,
            'attempts_left'  => max($maxAttempts - $s->attempts, 0),
            'otp_sends_left' => $s->step === 'aadhaar' ? max($maxSends - $s->otp_sends, 0) : null,
            'otp_resend_in'  => $s->step === 'aadhaar' ? $this->cooldownLeft($s) : null,
            'input_masked'   => $s->input_masked,
            'failure_reason' => $s->failure_reason,
            'result'         => $s->status === 'success' ? $this->publicResult($s) : null,
            'sign_url'       => ($s->step === 'esign' && $s->status === 'awaiting') ? ($s->meta['sign_url'] ?? null) : null,
        ])->values();

        $consents = $case->consents->map(fn (KycConsent $c) => [
            'type'         => $c->type,
            'channel'      => $c->channel,
            'purpose'      => config('kyc.purposes')[$c->purpose] ?? $c->purpose,
            'text_version' => $c->text_version,
            'accepted_at'  => $c->accepted_at?->toIso8601String(),
        ])->values();

        return [
            'id'                     => $case->id,
            'reference'              => $case->reference,
            'status'                 => $case->status,
            'current_step'           => $case->current_step,
            'customer_name'          => $case->customer_name,
            'customer_mobile_masked' => 'XXXXXX' . $case->customer_mobile_last4,
            'price'                  => (float) $case->price,
            'charged'                => $case->status === 'completed' ? (float) $case->price : 0.0,
            'failure_reason'         => $case->failure_reason,
            'expires_at'             => $case->expires_at?->toIso8601String(),
            'created_at'             => $case->created_at?->toIso8601String(),
            'completed_at'           => $case->completed_at?->toIso8601String(),
            'steps'                  => $steps,
            'consents'               => $consents,
            'wallet_available'       => (float) (Wallet::where('retailer_id', $case->retailer_id)->value('available_balance') ?? 0),
            // true only with the mock provider outside production: the app then shows test hints and the e-Sign simulator
            'dev_mode'               => $this->isDevMode(),
            // test mode only, and only in the answer to "send OTP"
            'dev_otp'                => $this->isDevMode() ? $this->devOtp : null,
        ];
    }

    public function isDevMode(): bool
    {
        return config('kyc.driver') === 'mock' && ! app()->isProduction();
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Runs one provider call for one step with all the safety checks:
     * case is open, earlier steps are done, step is in an allowed state, and only one request can run it.
     * If the provider is down, the step goes back to where it was and no try is used.
     *
     * @return array{0: KycStep, 1: ProviderResult}
     */
    private function attempt(KycCase $case, string $name, array $from, callable $call, ?callable $before = null): array
    {
        $this->ensureOpen($case);

        if ($case->current_step !== $name) {
            throw new KycException('Please complete the earlier steps first.', 409, 'wrong_step');
        }

        $step = $case->steps()->where('step', $name)->firstOrFail();

        if (! in_array($step->status, $from, true)) {
            throw new KycException('This step cannot be run right now.', 409, 'wrong_state');
        }

        if ($before) {
            $before($step);
        }

        $previous = $step->status;

        // Only one request can move the step to "processing" (double click, two tabs...)
        $claimed = KycStep::where('id', $step->id)->whereIn('status', $from)->update(['status' => 'processing']);

        if (! $claimed) {
            throw new KycException('This step is already being processed. Please wait a moment.', 409, 'in_progress');
        }

        try {
            $result = $call($step);
        } catch (Throwable $e) {
            // Log only the type. The message could contain customer input.
            Log::warning('KYC provider call failed', ['case_id' => $case->id, 'step' => $name, 'error' => get_class($e)]);

            KycStep::where('id', $step->id)->update(['status' => $previous]);

            throw new KycException('The verification service is not responding. Please try again in a moment.', 503, 'provider_down');
        }

        return [$step->fresh(), $result];
    }

    private function passStep(KycCase $case, KycStep $step, array $result, ?string $inputMasked = null, ?string $providerRef = null): void
    {
        DB::transaction(function () use ($case, $step, $result, $inputMasked, $providerRef) {
            $step->update([
                'status'         => 'success',
                'result'         => $result,
                'failure_reason' => null,
                'completed_at'   => now(),
                'input_masked'   => $inputMasked ?? $step->input_masked,
                'provider_ref'   => $providerRef ?? $step->provider_ref,
            ]);

            $index = array_search($step->step, self::STEPS, true);
            $next  = self::STEPS[$index + 1] ?? null;

            if ($next) {
                KycCase::where('id', $case->id)->update(['current_step' => $next]);
            } else {
                $this->complete($case);
            }
        });
    }

    // All 4 steps passed: the case is completed and the held money becomes the charge
    private function complete(KycCase $case): void
    {
        $done = KycCase::where('id', $case->id)
            ->where('status', 'in_progress')
            ->update(['status' => 'completed', 'current_step' => 'done', 'completed_at' => now(), 'closed_at' => now()]);

        if ($done && (float) $case->price > 0) {
            $this->wallets->debit(
                $case->retailer_id,
                (float) $case->price,
                self::reference($case->id, 'debit'),
                'Complete KYC charge'
            );
        }
    }

    // One failed try. When the tries are used up the whole KYC fails and the money is returned.
    private function failStep(KycCase $case, KycStep $step, string $reason, string $statusAfter, ?string $inputMasked = null): void
    {
        $step->increment('attempts');

        $exhausted = $step->attempts >= (int) config('kyc.max_attempts');

        $step->update([
            'status'         => $exhausted ? 'failed' : $statusAfter,
            'failure_reason' => Str::limit($reason, 250, ''),
            'input_masked'   => $inputMasked ?? $step->input_masked,
        ]);

        if ($exhausted) {
            $this->close($case, 'failed', self::LABELS[$step->step] . ' failed too many times. Amount returned to your wallet.');
        }
    }

    private function ensureOpen(KycCase $case): void
    {
        $case->refresh();

        if ($case->status === 'in_progress' && $case->expires_at->isPast()) {
            $this->close($case, 'expired', 'This KYC session expired. Amount returned to your wallet.');
            $case->refresh();
        }

        if ($case->status !== 'in_progress') {
            throw new KycException('This KYC is already ' . str_replace('_', ' ', $case->status) . '.', 409, 'case_closed');
        }
    }

    private function reload(KycCase $case): KycCase
    {
        return KycCase::with(['steps', 'consents'])->findOrFail($case->id);
    }

    private function aadhaarName(KycCase $case): string
    {
        $result = $case->steps()->where('step', 'aadhaar')->first()?->result;

        return (string) ($result['name'] ?? '');
    }

    private function cooldownLeft(KycStep $step): int
    {
        if (! $step->otp_sent_at) {
            return 0;
        }

        $left = (int) config('kyc.otp_cooldown_seconds') - (now()->timestamp - $step->otp_sent_at->timestamp);

        return max($left, 0);
    }

    // Only the last 4 characters stay visible
    private function mask(string $value): string
    {
        $len = strlen($value);

        return $len <= 4 ? str_repeat('X', $len) : str_repeat('X', $len - 4) . substr($value, -4);
    }

    private function consentText(string $customerName, string $retailerName, string $purposeLabel): string
    {
        return strtr((string) config('kyc.consent.text'), [
            '{customer_name}' => $customerName,
            '{retailer}'      => $retailerName,
            '{purpose}'       => $purposeLabel,
        ]);
    }

    // The letter the customer signs. Only masked identifiers are inside.
    private function letterText(KycCase $case): string
    {
        $steps   = $case->steps()->get()->keyBy('step');
        $consent = $case->consents()->where('type', 'collection')->first();
        $purpose = config('kyc.purposes')[$consent?->purpose] ?? 'KYC';

        return implode("\n", [
            'CONSENT LETTER',
            'Reference: ' . $case->reference,
            'Date: ' . now()->format('d M Y'),
            '',
            "I, {$case->customer_name}, confirm that the following details were verified for the purpose of {$purpose}:",
            '- Aadhaar (OTP): ' . ($steps['aadhaar']->input_masked ?? '-'),
            '- PAN: ' . ($steps['pan']->input_masked ?? '-'),
            '- Bank account: ' . ($steps['bank']->input_masked ?? '-'),
            '',
            'I consent to VerifyHub keeping the masked identifiers and the outcome of these checks for KYC records.',
            'Consent version: ' . config('kyc.consent.version'),
        ]);
    }

    // What the retailer may see after a step passed
    private function publicResult(KycStep $step): array
    {
        $r = $step->result ?? [];

        return match ($step->step) {
            'aadhaar' => ['name' => $r['name'] ?? null, 'aadhaar' => 'XXXXXXXX' . ($r['aadhaar_last4'] ?? '')],
            'pan'     => ['name' => $r['name'] ?? null, 'pan_status' => $r['pan_status'] ?? null, 'aadhaar_linked' => $r['aadhaar_linked'] ?? null, 'name_match_score' => $r['name_match_score'] ?? null],
            'bank'    => ['account_holder' => $r['account_holder'] ?? null, 'account_status' => $r['account_status'] ?? null, 'name_match_score' => $r['name_match_score'] ?? null],
            'esign'   => ['signed_at' => $r['signed_at'] ?? null, 'signed_doc_ref' => $r['signed_doc_ref'] ?? null],
            default   => [],
        };
    }

    private function provider(): KycProvider
    {
        $class = config('kyc.drivers.' . config('kyc.driver'));

        if (! $class) {
            throw new \RuntimeException('KYC provider is not configured.');
        }

        return app($class);
    }
}
