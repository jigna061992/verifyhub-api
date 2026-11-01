<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\KycException;
use App\Http\Controllers\Controller;
use App\Models\Verification;
use App\Services\SingleVerificationService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Retailer side: stand-alone Aadhaar (OTP) and e-Sign checks
class VerificationSessionController extends Controller
{
    public function __construct(private SingleVerificationService $service)
    {
    }

    // Purposes, consent text, price and wallet balance for the two screens
    public function options(Request $request)
    {
        return response()->json($this->service->options($this->retailer($request)));
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->present($this->findOwn($request, $id)));
    }

    /* ------------------------------ Aadhaar ------------------------------ */

    public function aadhaarStart(Request $request)
    {
        $request->merge(['aadhaar' => preg_replace('/\s+/', '', (string) $request->input('aadhaar'))]);

        $data = $request->validate([
            'aadhaar'       => ['required', 'regex:/^[2-9][0-9]{11}$/'],
            'customer_name' => $this->nameRule(),
            'purpose'       => ['required', Rule::in(array_keys(config('kyc.purposes')))],
            'consent'       => ['accepted'],
        ], [
            'aadhaar.regex'     => 'Aadhaar number must be 12 digits.',
            'consent.accepted'  => 'Customer consent must be confirmed before starting.',
        ]);

        $retailer = $this->retailer($request);

        return $this->respond(function () use ($request, $retailer, $data) {
            $v = $this->service->startAadhaar($retailer, $request->user(), $data, $request);
            Audit::log($request, 'verification.aadhaar.started', 'verification', $v->id, ['status' => $v->status]);

            return $this->service->present($v);
        }, 201, $retailer);
    }

    public function aadhaarResend(Request $request, int $id)
    {
        $request->merge(['aadhaar' => preg_replace('/\s+/', '', (string) $request->input('aadhaar'))]);
        $data = $request->validate(['aadhaar' => ['required', 'regex:/^[2-9][0-9]{11}$/']], ['aadhaar.regex' => 'Aadhaar number must be 12 digits.']);

        $v = $this->findOwn($request, $id);

        return $this->respond(function () use ($request, $v, $data) {
            $updated = $this->service->resendAadhaarOtp($v, $data['aadhaar']);
            Audit::log($request, 'verification.aadhaar.otp_resent', 'verification', $updated->id, []);

            return $this->service->present($updated);
        });
    }

    public function aadhaarVerify(Request $request, int $id)
    {
        $data = $request->validate(['otp' => ['required', 'regex:/^\d{6}$/']], ['otp.regex' => 'OTP must be 6 digits.']);

        $v = $this->findOwn($request, $id);

        return $this->respond(function () use ($request, $v, $data) {
            $updated = $this->service->verifyAadhaarOtp($v, $data['otp']);
            Audit::log($request, 'verification.aadhaar.checked', 'verification', $updated->id, ['status' => $updated->status]);

            return $this->service->present($updated);
        });
    }

    /* ------------------------------ e-Sign ------------------------------ */

    public function esignStart(Request $request)
    {
        $request->merge(['customer_mobile' => preg_replace('/\D+/', '', (string) $request->input('customer_mobile'))]);

        $data = $request->validate([
            'customer_name'   => $this->nameRule(),
            'customer_mobile' => ['required', 'regex:/^[6-9][0-9]{9}$/'],
            'purpose'         => ['required', Rule::in(array_keys(config('kyc.purposes')))],
            'consent'         => ['accepted'],
        ], [
            'customer_mobile.regex' => 'Mobile number must be 10 digits.',
            'consent.accepted'      => 'Customer consent must be confirmed before starting.',
        ]);

        $retailer = $this->retailer($request);

        return $this->respond(function () use ($request, $retailer, $data) {
            $v = $this->service->startEsign($retailer, $request->user(), $data, $request);
            Audit::log($request, 'verification.esign.started', 'verification', $v->id, ['status' => $v->status]);

            return $this->service->present($v);
        }, 201, $retailer);
    }

    // Development only (mock provider): pretend the customer signed or declined
    public function esignSimulate(Request $request, int $id)
    {
        abort_unless(config('kyc.driver') === 'mock' && ! app()->isProduction(), 404);

        $data = $request->validate(['outcome' => ['nullable', Rule::in(['signed', 'declined'])]]);
        $v    = $this->findOwn($request, $id);

        return $this->respond(function () use ($request, $v, $data) {
            if ($v->service?->code !== 'ESIGN' || $v->status !== 'pending' || $v->stage !== 'signing' || ! $v->provider_ref) {
                throw new KycException('e-Sign is not waiting for the customer.', 409, 'wrong_state');
            }

            $outcome = $data['outcome'] ?? 'signed';
            $this->service->handleEsignResult($v->provider_ref, $outcome, $outcome === 'signed' ? 'MOCK-SIGNED-' . strtoupper(Str::random(8)) : null);
            Audit::log($request, 'verification.esign.' . $outcome, 'verification', $v->id, []);

            return $this->service->present(Verification::with('service:id,code,name')->findOrFail($v->id));
        });
    }

    /* ------------------------------ Both ------------------------------ */

    public function cancel(Request $request, int $id)
    {
        $v = $this->findOwn($request, $id);

        return $this->respond(function () use ($request, $v) {
            $updated = $this->service->cancel($v);
            Audit::log($request, 'verification.cancelled', 'verification', $updated->id, []);

            return $this->service->present($updated);
        });
    }

    /* ------------------------------ Helpers ------------------------------ */

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }

    private function nameRule(): array
    {
        return ['required', 'string', 'min:2', 'max:100', "regex:/^[\\pL][\\pL .'-]*$/u"];
    }

    // Only the logged-in retailer's own Aadhaar / e-Sign checks can be opened. Anything else is a 404.
    private function findOwn(Request $request, int $id): Verification
    {
        return Verification::with('service:id,code,name')
            ->where('retailer_id', $this->retailer($request)->id)
            ->whereHas('service', fn ($s) => $s->whereIn('code', SingleVerificationService::CODES))
            ->findOrFail($id);
    }

    // Runs the action and turns known problems into clean JSON errors
    private function respond(callable $action, int $status = 200, $retailer = null)
    {
        try {
            return response()->json($action(), $status);
        } catch (InsufficientBalanceException) {
            return response()->json([
                'code'    => 'insufficient_balance',
                'message' => 'Insufficient wallet balance. Please add money to your wallet.',
            ], 422);
        } catch (KycException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }
}