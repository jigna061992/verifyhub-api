<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\KycException;
use App\Http\Controllers\Controller;
use App\Services\KycService;
use App\Services\SingleVerificationService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Called by the e-Sign provider (no login). The request is trusted only if its signature is correct.
class KycWebhookController extends Controller
{
    public function __construct(private KycService $kyc, private SingleVerificationService $single)
    {
    }

    /**
     * POST /api/webhooks/esign
     * Header  X-Signature: hex HMAC-SHA256 of the raw request body, key = KYC_ESIGN_WEBHOOK_SECRET
     * Body    { "request_id": "...", "status": "signed|declined|expired", "signed_doc_ref": "..." }
     */
    public function esign(Request $request)
    {
        $secret = (string) config('kyc.esign_webhook_secret');

        // No secret configured = webhook switched off. Never accept unsigned calls.
        abort_if($secret === '', 503, 'Webhook is not configured.');

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Signature'))) {
            abort(401, 'Invalid signature.');
        }

        $data = $request->validate([
            'request_id'     => ['required', 'string', 'max:100'],
            'status'         => ['required', Rule::in(['signed', 'declined', 'expired'])],
            'signed_doc_ref' => ['nullable', 'string', 'max:150'],
        ]);

        // A stand-alone e-Sign check? If not, it belongs to a Complete KYC case.
        if ($this->single->handleEsignResult($data['request_id'], $data['status'], $data['signed_doc_ref'] ?? null)) {
            Audit::log($request, 'verification.esign.webhook', 'kyc_webhook', 0, ['status' => $data['status']]);

            return response()->json(['received' => true]);
        }

        try {
            $this->kyc->handleEsignResult($data['request_id'], $data['status'], $data['signed_doc_ref'] ?? null);
        } catch (KycException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        Audit::log($request, 'kyc.esign.webhook', 'kyc_webhook', 0, ['status' => $data['status']]);

        // Same answer for a repeated webhook, so the provider stops retrying
        return response()->json(['received' => true]);
    }
}
