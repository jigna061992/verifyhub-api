<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\KycException;
use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Wallet;
use App\Services\KycService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Retailer side: Complete KYC (Aadhaar OTP -> PAN -> Bank -> e-Sign)
class KycController extends Controller
{
    private const AADHAAR_REGEX = '/^[2-9][0-9]{11}$/';
    private const PAN_REGEX     = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';
    private const IFSC_REGEX    = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public function __construct(private KycService $kyc)
    {
    }

    // Price, wallet balance, purposes and the consent text for the "Start KYC" screen
    public function options(Request $request)
    {
        $retailer = $this->retailer($request);
        $price    = $this->kyc->priceFor($retailer);

        return response()->json([
            'available'        => $price !== null,
            'price'            => $price,
            'wallet_available' => (float) (Wallet::where('retailer_id', $retailer->id)->value('available_balance') ?? 0),
            'purposes'         => collect(config('kyc.purposes'))->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'consent'          => ['version' => config('kyc.consent.version'), 'text' => config('kyc.consent.text')],
            'ttl_minutes'      => (int) config('kyc.case_ttl_minutes'),
            'steps'            => collect(KycService::STEPS)->map(fn ($s) => ['step' => $s, 'label' => KycService::LABELS[$s]])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $retailer = $this->retailer($request);
        $perPage  = min(max((int) $request->query('per_page', 10), 1), 100);
        $status   = $request->query('status');

        $query = KycCase::where('retailer_id', $retailer->id)->latest()->latest('id');

        if (in_array($status, ['in_progress', 'completed', 'failed', 'cancelled', 'expired'], true)) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage)->through(fn (KycCase $c) => [
            'id'                     => $c->id,
            'reference'              => $c->reference,
            'customer_name'          => $c->customer_name,
            'customer_mobile_masked' => 'XXXXXX' . $c->customer_mobile_last4,
            'status'                 => $c->status,
            'current_step'           => $c->current_step,
            'price'                  => (float) $c->price,
            'charged'                => $c->status === 'completed' ? (float) $c->price : 0.0,
            'created_at'             => $c->created_at?->toIso8601String(),
            'expires_at'             => $c->expires_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request)
    {
        $retailer = $this->retailer($request);

        $data = $request->validate([
            'customer_name'   => ['required', 'string', 'min:2', 'max:100', "regex:/^[\pL .'-]+$/u"],
            'customer_mobile' => ['required', 'digits:10'],
            'purpose'         => ['required', Rule::in(array_keys(config('kyc.purposes')))],
            'consent'         => ['accepted'],
        ], [
            'customer_name.regex' => 'Customer name can contain only letters and spaces.',
            'consent.accepted'    => 'Customer consent must be confirmed before starting.',
        ]);

        return $this->respond(function () use ($request, $retailer, $data) {
            $case = $this->kyc->start($retailer, $request->user(), $data, $request);

            Audit::log($request, 'kyc.started', 'kyc_case', $case->id, [
                'purpose' => $data['purpose'],
                'price'   => (float) $case->price,
            ]);

            return $this->kyc->present($case);
        }, 201, $retailer);
    }

    public function show(Request $request, int $id)
    {
        $case = $this->kyc->expireIfDue($this->findCase($request, $id));

        return response()->json($this->kyc->present($case));
    }

    public function aadhaarOtp(Request $request, int $id)
    {
        // Spaces are allowed while typing (1234 5678 9012)
        $request->merge(['aadhaar_number' => preg_replace('/\s+/', '', (string) $request->input('aadhaar_number'))]);

        $data = $request->validate(
            ['aadhaar_number' => ['required', 'regex:' . self::AADHAAR_REGEX]],
            ['aadhaar_number.regex' => 'Aadhaar number must be 12 digits.']
        );

        $case = $this->findCase($request, $id);

        // The full Aadhaar number is only handed to the provider. It is never saved, logged or audited.
        return $this->respond(function () use ($request, $case, $data) {
            $updated = $this->kyc->sendAadhaarOtp($case, $data['aadhaar_number']);
            $this->auditStep($request, $updated, 'aadhaar', 'otp_sent');

            return $this->kyc->present($updated);
        });
    }

    public function aadhaarVerify(Request $request, int $id)
    {
        $data = $request->validate(['otp' => ['required', 'digits:6']], ['otp.digits' => 'OTP must be 6 digits.']);

        $case = $this->findCase($request, $id);

        return $this->respond(function () use ($request, $case, $data) {
            $updated = $this->kyc->verifyAadhaarOtp($case, $data['otp']);
            $this->auditStep($request, $updated, 'aadhaar', 'otp_verified');

            return $this->kyc->present($updated);
        });
    }

    public function pan(Request $request, int $id)
    {
        $request->merge(['pan' => strtoupper(trim((string) $request->input('pan')))]);

        $data = $request->validate(
            ['pan' => ['required', 'regex:' . self::PAN_REGEX]],
            ['pan.regex' => 'PAN format is invalid (example: ABCDE1234F).']
        );

        $case = $this->findCase($request, $id);

        return $this->respond(function () use ($request, $case, $data) {
            $updated = $this->kyc->verifyPan($case, $data['pan']);
            $this->auditStep($request, $updated, 'pan', 'checked');

            return $this->kyc->present($updated);
        });
    }

    public function bank(Request $request, int $id)
    {
        $request->merge([
            'account_number' => trim((string) $request->input('account_number')),
            'ifsc'           => strtoupper(trim((string) $request->input('ifsc'))),
        ]);

        $data = $request->validate([
            'account_number' => ['required', 'regex:/^\d{9,18}$/'],
            'ifsc'           => ['required', 'regex:' . self::IFSC_REGEX],
        ], [
            'account_number.regex' => 'Account number must be 9 to 18 digits.',
            'ifsc.regex'           => 'IFSC code is invalid (example: SBIN0001234).',
        ]);

        $case = $this->findCase($request, $id);

        return $this->respond(function () use ($request, $case, $data) {
            $updated = $this->kyc->verifyBank($case, $data['account_number'], $data['ifsc']);
            $this->auditStep($request, $updated, 'bank', 'checked');

            return $this->kyc->present($updated);
        });
    }

    public function esignStart(Request $request, int $id)
    {
        $case = $this->findCase($request, $id);

        return $this->respond(function () use ($request, $case) {
            $updated = $this->kyc->startEsign($case);
            $this->auditStep($request, $updated, 'esign', 'started');

            return $this->kyc->present($updated);
        });
    }

    // Development only (mock provider): pretend the customer signed or declined
    public function esignSimulate(Request $request, int $id)
    {
        abort_unless($this->kyc->isDevMode(), 404);

        $data = $request->validate(['outcome' => ['nullable', Rule::in(['signed', 'declined'])]]);

        $case = $this->findCase($request, $id);
        $step = $case->steps->firstWhere('step', 'esign');

        return $this->respond(function () use ($request, $case, $step, $data) {
            if (! $step || $step->status !== 'awaiting' || ! $step->provider_ref) {
                throw new KycException('e-Sign has not been started.', 409, 'wrong_state');
            }

            $outcome = $data['outcome'] ?? 'signed';
            $this->kyc->handleEsignResult($step->provider_ref, $outcome, $outcome === 'signed' ? 'MOCK-SIGNED-' . strtoupper(Str::random(8)) : null);

            $updated = KycCase::with(['steps', 'consents'])->findOrFail($case->id);
            $this->auditStep($request, $updated, 'esign', $outcome);

            return $this->kyc->present($updated);
        });
    }

    public function cancel(Request $request, int $id)
    {
        $case = $this->findCase($request, $id);

        return $this->respond(function () use ($request, $case) {
            $updated = $this->kyc->cancel($case);
            Audit::log($request, 'kyc.cancelled', 'kyc_case', $updated->id, []);

            return $this->kyc->present($updated);
        });
    }

    /* ------------------------------------------------------------------ */

    // Runs the action and turns known problems into clean JSON errors
    private function respond(callable $action, int $status = 200, $retailer = null)
    {
        try {
            return response()->json($action(), $status);
        } catch (InsufficientBalanceException) {
            $price = $retailer ? $this->kyc->priceFor($retailer) : null;

            return response()->json([
                'code'    => 'insufficient_balance',
                'message' => 'Insufficient wallet balance.' . ($price !== null ? ' Complete KYC costs Rs ' . number_format($price, 2) . '.' : '') . ' Please add money to your wallet.',
            ], 422);
        } catch (KycException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }

    // Only the logged-in retailer's own cases can be opened. Anything else is a 404.
    private function findCase(Request $request, int $id): KycCase
    {
        return KycCase::with(['steps', 'consents'])
            ->where('retailer_id', $this->retailer($request)->id)
            ->findOrFail($id);
    }

    // Never put identifiers in the audit metadata. Only the step name and its status.
    private function auditStep(Request $request, KycCase $case, string $stepName, string $action): void
    {
        $step = $case->steps->firstWhere('step', $stepName);

        Audit::log($request, "kyc.{$stepName}.{$action}", 'kyc_case', $case->id, [
            'step_status' => $step?->status,
            'case_status' => $case->status,
        ]);
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }
}
