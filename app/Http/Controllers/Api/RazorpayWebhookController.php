<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\RazorpayPayment;
use App\Services\RazorpayService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Called by Razorpay (no login). Trusted only if the X-Razorpay-Signature header is correct.
// It credits the wallet even when the customer closed the browser right after paying.
class RazorpayWebhookController extends Controller
{
    public function __construct(private RazorpayService $razorpay)
    {
    }

    /**
     * POST /api/webhooks/razorpay
     * Events used: payment.captured, order.paid (credit the wallet) and payment.failed (save the reason).
     */
    public function handle(Request $request)
    {
        abort_if((string) config('services.razorpay.webhook_secret') === '', 503, 'Webhook is not configured.');

        if (! $this->razorpay->verifyWebhookSignature($request->getContent(), $request->header('X-Razorpay-Signature'))) {
            abort(401, 'Invalid signature.');
        }

        $event   = (string) $request->input('event');
        $payment = $request->input('payload.payment.entity', []);
        $orderId = (string) ($payment['order_id'] ?? $request->input('payload.order.entity.id', ''));

        $row = $orderId !== '' ? RazorpayPayment::where('order_id', $orderId)->first() : null;

        // An order that is not ours (another project using the same account): nothing to do, answer 200 so Razorpay stops retrying
        if (! $row) {
            return response()->json(['received' => true]);
        }

        try {
            if (in_array($event, ['payment.captured', 'order.paid'], true) && ! empty($payment['id'])) {
                if ($this->razorpay->settle($row, (string) $payment['id'], (int) ($payment['amount'] ?? 0), 'webhook')) {
                    Audit::log($request, 'wallet.razorpay.paid', 'razorpay_payment', $row->id, ['amount' => (float) $row->amount, 'via' => 'webhook']);
                }
            } elseif ($event === 'payment.failed' && $row->status === 'created') {
                $row->update(['last_error' => Str::limit((string) ($payment['error_description'] ?? 'Payment failed'), 190, '')]);
            }
        } catch (PaymentException $e) {
            // Wrong amount: do not credit, keep a trace, still answer 200 (a retry would fail the same way)
            Log::warning('Razorpay webhook not applied', ['order' => $orderId, 'code' => $e->errorCode]);
        }

        return response()->json(['received' => true]);
    }
}
