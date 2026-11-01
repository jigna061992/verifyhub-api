<?php

namespace App\Services;

use App\Exceptions\KycException;
use App\Models\Retailer;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationConsent;
use App\Models\VerificationService;
use App\Models\Wallet;
use App\Services\Kyc\KycProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The two stand-alone checks that need more than one call:
 *
 *  AADHAAR : send OTP -> customer tells the OTP -> verify OTP
 *  ESIGN   : send the consent letter -> customer signs -> provider webhook tells us
 *
 * Money works like the other checks: the price is held (reserved) when the check starts,
 * debited only when it succeeds, and released when it fails, is cancelled or expires.
 * The Aadhaar number and the OTP are never stored, logged or put into an exception message.
 */
class SingleVerificationService
{
    public const CODES = ['AADHAAR', 'ESIGN'];

    public function __construct(private WalletService $wallets, private VerificationRunner $runner)
    {
    }

    /* ------------------------------------------------------------------
     | Options for the screen
     * ------------------------------------------------------------------ */

    public function options(Retailer $retailer): array
    {
        $services = VerificationService::whereIn('code', self::CODES)->where('enabled', true)->get()->keyBy('code');

        return [
            'retailer'         => $retailer->business_name,
            'wallet_available' => (float) (Wallet::where('retailer_id', $retailer->id)->value('available_balance') ?? 0),
            'purposes'         => collect(config('kyc.purposes'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'consent'          => [
                'AADHAAR' => config('verification.consent.aadhaar'),
                'ESIGN'   => config('verification.consent.esign'),
            ],
            'ttl_minutes'      => [
                'AADHAAR' => (int) config('verification.sessions.aadhaar_ttl_minutes'),
                'ESIGN'   => (int) config('verification.sessions.esign_ttl_minutes'),
            ],
            'dev_mode'         => $this->isDevMode(),
            'prices'           => $services->map(fn ($s) => $this->runner->priceFor($retailer, $s))->all(),
        ];
    }

    /* ------------------------------------------------------------------
     | Aadhaar
     * ------------------------------------------------------------------ */

    /** @param array $data aadhaar, customer_name, purpose */
    public function startAadhaar(Retailer $retailer, User $user, array $data, Request $request): Verification
    {
        $service = $this->service('AADHAAR');
        $last4   = substr($data['aadhaar'], -4);

        $v = $this->create($retailer, $user, $service, $request, [
            'input_masked' => 'XXXXXXXX' . $last4,
            'ttl'          => (int) config('verification.sessions.aadhaar_ttl_minutes'),
            'stage'        => 'processing',
            'meta'         => ['customer_name' => $data['customer_name'], 'purpose' => $data['purpose']],
            'consent'      => 'aadhaar',
        ], $data);

        try {
            $result = $this->provider()->aadhaarSendOtp($data['aadhaar']);
        } catch (Throwable $e) {
            $this->logProviderError($v, $e);
            $this->settle($v, false, [], 'Verification service is not responding. Please try again. You were not charged.');

            return $this->fresh($v);
        }

        if (! $result->success) {
            $this->settle($v, false, [], $result->error ?? 'Could not send the OTP.', $result->providerRef);

            return $this->fresh($v);
        }

        Verification::where('id', $v->id)->where('status', 'pending')->update([
            'stage'        => 'otp_sent',
            'provider_ref' => $result->providerRef,
            'otp_sends'    => 1,
            'otp_sent_at'  => now(),
        ]);

        return $this->fresh($v);
    }

    public function resendAadhaarOtp(Verification $v, string $aadhaar): Verification
    {
        $this->ensureOpen($v, 'AADHAAR');

        if ($v->stage !== 'otp_sent') {
            throw new KycException('An OTP cannot be sent right now.', 409, 'wrong_state');
        }

        // The same Aadhaar as the first request (we only keep the last 4 digits to compare)
        if (substr($aadhaar, -4) !== substr((string) $v->input_masked, -4)) {
            throw new KycException('This is not the Aadhaar number of the first request. Start a new check for another customer.', 422, 'aadhaar_mismatch');
        }

        if ($v->otp_sends >= (int) config('verification.sessions.otp_max_sends')) {
            throw new KycException('OTP limit reached for this check. Please cancel it and start again.', 429, 'otp_limit');
        }

        $wait = $this->cooldownLeft($v);
        if ($wait > 0) {
            throw new KycException("Please wait {$wait} seconds before requesting a new OTP.", 429, 'otp_cooldown');
        }

        $this->claim($v, 'otp_sent');

        try {
            $result = $this->provider()->aadhaarSendOtp($aadhaar);
        } catch (Throwable $e) {
            $this->logProviderError($v, $e);
            Verification::where('id', $v->id)->update(['stage' => 'otp_sent']);

            throw new KycException('The verification service is not responding. Please try again in a moment.', 503, 'provider_down');
        }

        if (! $result->success) {
            $this->settle($v, false, [], $result->error ?? 'Could not send the OTP.', $result->providerRef);

            return $this->fresh($v);
        }

        Verification::where('id', $v->id)->where('status', 'pending')->update([
            'stage'        => 'otp_sent',
            'provider_ref' => $result->providerRef,
            'otp_sends'    => DB::raw('otp_sends + 1'),
            'otp_sent_at'  => now(),
        ]);

        return $this->fresh($v);
    }

    public function verifyAadhaarOtp(Verification $v, string $otp): Verification
    {
        $this->ensureOpen($v, 'AADHAAR');

        if ($v->stage !== 'otp_sent') {
            throw new KycException('Please send the OTP first.', 409, 'wrong_state');
        }

        $this->claim($v, 'otp_sent');

        try {
            $result = $this->provider()->aadhaarVerifyOtp((string) $v->provider_ref, $otp);
        } catch (Throwable $e) {
            $this->logProviderError($v, $e);
            Verification::where('id', $v->id)->update(['stage' => 'otp_sent']);

            throw new KycException('The verification service is not responding. Please try again in a moment.', 503, 'provider_down');
        }

        if ($result->success) {
            $this->settle($v, true, [
                'name'          => (string) ($result->data['name'] ?? ''),
                'aadhaar_last4' => substr((string) $v->input_masked, -4),
            ], null, $result->providerRef);

            return $this->fresh($v);
        }

        // Wrong OTP: the customer can type it again until the tries are used up
        $max = (int) config('verification.sessions.max_attempts');
        Verification::where('id', $v->id)->update(['attempts' => DB::raw('attempts + 1'), 'stage' => 'otp_sent']);
        $left = $max - ($v->attempts + 1);

        if ($left <= 0) {
            $this->settle($v, false, [], 'Wrong OTP entered too many times.');

            return $this->fresh($v);
        }

        throw new KycException(($result->error ?? 'Incorrect OTP.') . " {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.', 422, 'wrong_otp');
    }

    /* ------------------------------------------------------------------
     | e-Sign
     * ------------------------------------------------------------------ */

    /** @param array $data customer_name, customer_mobile, purpose */
    public function startEsign(Retailer $retailer, User $user, array $data, Request $request): Verification
    {
        $service = $this->service('ESIGN');

        $v = $this->create($retailer, $user, $service, $request, [
            'input_masked' => 'XXXXXX' . substr($data['customer_mobile'], -4),
            'ttl'          => (int) config('verification.sessions.esign_ttl_minutes'),
            'stage'        => 'processing',
            'meta'         => ['customer_name' => $data['customer_name'], 'purpose' => $data['purpose']],
            'consent'      => 'esign',
        ], $data);

        $letter = $this->letterText($v, $retailer, $data);
        $hash   = hash('sha256', $letter);

        try {
            $result = $this->provider()->esignStart([
                'reference'       => $v->reference,
                'customer_name'   => $data['customer_name'],
                'customer_mobile' => $data['customer_mobile'],   // goes to the provider only, never saved
                'letter_text'     => $letter,
                'letter_hash'     => $hash,
            ]);
        } catch (Throwable $e) {
            $this->logProviderError($v, $e);
            $this->settle($v, false, [], 'Verification service is not responding. Please try again. You were not charged.');

            return $this->fresh($v);
        }

        if (! $result->success) {
            $this->settle($v, false, [], $result->error ?? 'Could not start e-Sign.', $result->providerRef);

            return $this->fresh($v);
        }

        Verification::where('id', $v->id)->where('status', 'pending')->update([
            'stage'        => 'signing',
            'provider_ref' => $result->providerRef,
        ]);

        // meta is encrypted, so it is saved through the model (a raw update would skip the cast)
        $fresh = Verification::find($v->id);
        $fresh->meta = ($fresh->meta ?? []) + [
            'sign_url'    => $result->data['sign_url'] ?? null,
            'letter_text' => $letter,
            'letter_hash' => $hash,
        ];
        $fresh->save();

        return $this->fresh($v);
    }

    /**
     * The customer signed, declined, or the request expired. Called by the provider webhook (after its signature
     * is checked) and by the development simulator. Safe to call twice.
     *
     * @return bool false = this request id does not belong to a stand-alone e-Sign check (the caller can try Complete KYC)
     */
    public function handleEsignResult(string $requestId, string $outcome, ?string $documentRef = null): bool
    {
        $v = Verification::whereHas('service', fn ($s) => $s->where('code', 'ESIGN'))
            ->where('provider_ref', $requestId)
            ->first();

        if (! $v) {
            return false;
        }

        if ($v->status !== 'pending') {
            return true; // duplicate webhook, or already expired / cancelled
        }

        if ($outcome !== 'signed') {
            $this->settle($v, false, [], $outcome === 'declined' ? 'The customer declined to sign.' : 'The signing request expired.');

            return true;
        }

        $meta = $v->meta ?? [];

        $this->settle($v, true, [
            'signed_at'      => now()->toIso8601String(),
            'signed_doc_ref' => $documentRef,
        ], null, null, function (Verification $locked) use ($meta, $requestId) {
            VerificationConsent::create([
                'verification_id' => $locked->id,
                'type'            => 'signed_letter',
                'purpose'         => (string) ($meta['purpose'] ?? 'general_kyc'),
                'text_version'    => (string) config('verification.consent.esign.version'),
                'text_hash'       => (string) ($meta['letter_hash'] ?? ''),
                'text'            => (string) ($meta['letter_text'] ?? ''),
                'channel'         => 'esign',
                'provider_ref'    => $requestId,
                'accepted_at'     => now(),
            ]);
        });

        return true;
    }

    /* ------------------------------------------------------------------
     | Cancel, expire, show
     * ------------------------------------------------------------------ */

    public function cancel(Verification $v): Verification
    {
        $this->ensureOpen($v, $v->service?->code);

        if ($v->stage === 'processing') {
            throw new KycException('This check is being processed. Please wait a moment.', 409, 'in_progress');
        }

        $this->settle($v, false, [], 'Cancelled by you. Amount returned to your wallet.');

        return $this->fresh($v);
    }

    // Used by the scheduled command: closes checks that were left open and returns the held money
    public function expireDue(): int
    {
        $expired = 0;

        Verification::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$expired) {
                foreach ($rows as $v) {
                    if ($this->settle($v, false, [], 'This check expired. Amount returned to your wallet.')) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    public function isDevMode(): bool
    {
        return config('kyc.driver') === 'mock' && ! app()->isProduction();
    }

    // What the screen may see. No Aadhaar number, no OTP.
    public function present(Verification $v): array
    {
        $v->loadMissing('service:id,code,name');

        $open   = $v->status === 'pending';
        $meta   = $v->meta ?? [];
        $result = $v->status === 'success' ? ($v->result ?? []) : null;

        if ($result && $v->service?->code === 'AADHAAR') {
            $result = ['name' => $result['name'] ?? null, 'aadhaar' => 'XXXXXXXX' . ($result['aadhaar_last4'] ?? '')];
        }

        return [
            'id'               => $v->id,
            'service_code'     => $v->service?->code,
            'service_name'     => $v->service?->name,
            'status'           => $v->status,
            'stage'            => $open ? $v->stage : null,
            'input_masked'     => $v->input_masked,
            'customer_name'    => $meta['customer_name'] ?? null,
            'price'            => (float) $v->price,
            'charged'          => $v->status === 'success' ? (float) $v->price : 0.0,
            'failure_reason'   => $v->failure_reason,
            'result'           => $result,
            'otp'              => $open && $v->service?->code === 'AADHAAR' ? [
                'sends_left'     => max((int) config('verification.sessions.otp_max_sends') - $v->otp_sends, 0),
                'tries_left'     => max((int) config('verification.sessions.max_attempts') - $v->attempts, 0),
                'cooldown_left'  => $this->cooldownLeft($v),
            ] : null,
            'sign_url'         => $open && $v->stage === 'signing' ? ($meta['sign_url'] ?? null) : null,
            'expires_at'       => $open ? $v->expires_at?->toIso8601String() : null,
            'wallet_available' => (float) (Wallet::where('retailer_id', $v->retailer_id)->value('available_balance') ?? 0),
            // true only with the mock provider outside production: the screen shows test hints and the e-Sign simulator
            'dev_mode'         => $this->isDevMode(),
            'created_at'       => $v->created_at?->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function service(string $code): VerificationService
    {
        $service = VerificationService::where('code', $code)->where('enabled', true)->first();

        if (! $service) {
            throw new KycException('This service is not available right now.', 422, 'service_unavailable');
        }

        return $service;
    }

    /**
     * Creates the row, the consent log row and holds the price in one transaction.
     * Throws InsufficientBalanceException when the wallet is low (nothing is saved then).
     */
    private function create(Retailer $retailer, User $user, VerificationService $service, Request $request, array $opt, array $data): Verification
    {
        $price        = $this->runner->priceFor($retailer, $service);
        $purposeLabel = config('kyc.purposes')[$data['purpose']];
        $text         = strtr((string) config('verification.consent.' . $opt['consent'] . '.text'), [
            '{customer_name}' => $data['customer_name'],
            '{retailer}'      => $retailer->business_name,
            '{purpose}'       => $purposeLabel,
        ]);

        return DB::transaction(function () use ($retailer, $user, $service, $request, $opt, $data, $price, $text) {
            $v = Verification::create([
                'retailer_id'   => $retailer->id,
                'service_id'    => $service->id,
                'reference'     => (string) Str::uuid(),
                'status'        => 'pending',
                'stage'         => $opt['stage'],
                'input_masked'  => $opt['input_masked'],
                'meta'          => $opt['meta'],
                'price'         => $price,
                'provider_cost' => $service->provider_cost,
                'expires_at'    => now()->addMinutes($opt['ttl']),
            ]);

            VerificationConsent::create([
                'verification_id' => $v->id,
                'type'            => 'collection',
                'purpose'         => $data['purpose'],
                'text_version'    => (string) config('verification.consent.' . $opt['consent'] . '.version'),
                'text_hash'       => hash('sha256', $text),
                'text'            => $text,
                'channel'         => 'retailer_assisted',
                'accepted_by'     => $user->id,
                'ip_address'      => $request->ip(),
                'user_agent'      => Str::limit((string) $request->userAgent(), 250, ''),
                'accepted_at'     => now(),
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
    }

    /**
     * Closes the check exactly once. Locks the row, so two callers (webhook + expiry command, double click...) cannot both settle it.
     * success = debit the held money, otherwise = release it back.
     *
     * @return bool true when THIS call closed it
     */
    private function settle(Verification $v, bool $success, array $result, ?string $reason, ?string $providerRef = null, ?callable $inside = null): bool
    {
        return DB::transaction(function () use ($v, $success, $result, $reason, $providerRef, $inside) {
            $locked = Verification::with('service:id,name')->lockForUpdate()->find($v->id);

            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $price = (float) $locked->price;
            $name  = $locked->service?->name ?? 'Verification';

            if ($inside) {
                $inside($locked);
            }

            if ($success) {
                if ($price > 0) {
                    $this->wallets->debit($locked->retailer_id, $price, WalletService::verificationReference($locked->id, 'debit'), "{$name} charge");
                }

                $locked->status = 'success';
                $locked->result = $result;
            } else {
                if ($price > 0) {
                    $this->wallets->release($locked->retailer_id, $price, WalletService::verificationReference($locked->id, 'release'), "{$name} not completed, amount returned");
                }

                $locked->status         = 'failed';
                $locked->failure_reason = $reason;
            }

            $locked->stage = null;

            if ($providerRef) {
                $locked->provider_ref = $providerRef;
            }

            $locked->save();

            return true;
        });
    }

    // Only one request can move the check to "processing" (double click, two tabs...)
    private function claim(Verification $v, string $from): void
    {
        $claimed = Verification::where('id', $v->id)->where('status', 'pending')->where('stage', $from)->update(['stage' => 'processing']);

        if (! $claimed) {
            throw new KycException('This check is already being processed. Please wait a moment.', 409, 'in_progress');
        }
    }

    private function ensureOpen(Verification $v, ?string $code): void
    {
        if (! in_array($code, self::CODES, true)) {
            throw new KycException('This check is not available here.', 404, 'not_found');
        }

        if ($v->status !== 'pending') {
            throw new KycException('This check is already ' . $v->status . '.', 409, 'closed');
        }

        if ($v->expires_at && $v->expires_at->isPast()) {
            $this->settle($v, false, [], 'This check expired. Amount returned to your wallet.');

            throw new KycException('This check has expired. Amount returned to your wallet.', 409, 'expired');
        }
    }

    private function cooldownLeft(Verification $v): int
    {
        if (! $v->otp_sent_at) {
            return 0;
        }

        return max((int) config('verification.sessions.otp_cooldown_seconds') - (now()->timestamp - $v->otp_sent_at->timestamp), 0);
    }

    private function letterText(Verification $v, Retailer $retailer, array $data): string
    {
        return strtr((string) config('verification.esign_letter'), [
            '{reference}'     => $v->reference,
            '{date}'          => now()->format('d M Y'),
            '{customer_name}' => $data['customer_name'],
            '{retailer}'      => $retailer->business_name,
            '{purpose}'       => config('kyc.purposes')[$data['purpose']],
            '{version}'       => (string) config('verification.consent.esign.version'),
        ]);
    }

    private function fresh(Verification $v): Verification
    {
        return Verification::with('service:id,code,name')->findOrFail($v->id);
    }

    // Log only the exception type. The message could contain customer input.
    private function logProviderError(Verification $v, Throwable $e): void
    {
        Log::warning('Verification provider call failed', ['verification_id' => $v->id, 'error' => get_class($e)]);
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
