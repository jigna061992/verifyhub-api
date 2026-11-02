<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\RazorpayPayment;
use App\Services\RazorpayService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Retailer side of the online wallet top-up. A retailer can only ever touch their OWN orders.
class RazorpayController extends Controller
{
    public function __construct(private RazorpayService $razorpay)
    {
    }

    // The screen asks this first: is online payment available, and which public key to use
    public function config(Request $request)
    {
        $this->retailer($request);

        $enabled = $this->razorpay->isEnabled();

        return response()->json([
            'enabled'    => $enabled,
            'mode'       => $this->razorpay->mode(),
            'key_id'     => $enabled ? (string) config('services.razorpay.key_id') : null,   // public key only, the secret never leaves the server
            'min_amount' => (int) config('services.razorpay.min_amount'),
            'max_amount' => (int) config('services.razorpay.max_amount'),
        ]);
    }

    // Step 1: create the order. The amount is checked here and sent to Razorpay by the server.
    public function createOrder(Request $request)
    {
        $retailer = $this->retailer($request);

        $min = (int) config('services.razorpay.min_amount');
        $max = (int) config('services.razorpay.max_amount');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', "min:{$min}", "max:{$max}"],
        ], [
            'amount.min' => "Minimum top-up amount is Rs {$min}.",
            'amount.max' => 'Maximum top-up amount per payment is Rs ' . number_format($max) . '.',
        ]);

        return $this->respond(function () use ($request, $retailer, $data) {
            $amount = round((float) $data['amount'], 2);

            $order = $this->razorpay->createOrder($amount, 'vh-' . $retailer->id . '-' . Str::lower(Str::random(10)), [
                'retailer_id' => (string) $retailer->id,
                'purpose'     => 'wallet_topup',
            ]);

            $payment = RazorpayPayment::create([
                'retailer_id'  => $retailer->id,
                'order_id'     => (string) $order['id'],
                'amount'       => $amount,
                'amount_paise' => (int) $order['amount'],
                'currency'     => (string) ($order['currency'] ?? 'INR'),
                'mode'         => $this->razorpay->mode(),
            ]);

            Audit::log($request, 'wallet.razorpay.order_created', 'razorpay_payment', $payment->id, ['amount' => $amount, 'mode' => $payment->mode]);

            $user = $request->user();

            return [
                'order_id'     => $payment->order_id,
                'amount_paise' => $payment->amount_paise,
                'currency'     => $payment->currency,
                'key_id'       => (string) config('services.razorpay.key_id'),
                'mode'         => $payment->mode,
                'prefill'      => [
                    'name'    => $user->name,
                    'email'   => $user->email,
                    'contact' => $user->mobile,
                ],
            ];
        }, 201);
    }

    /**
     * Step 3: the browser calls this after the customer paid.
     * The signature is checked, the payment is confirmed with Razorpay, then the wallet is credited once.
     * Calling it again (retry, double click, webhook came first) never credits twice.
     */
    public function verify(Request $request)
    {
        $retailer = $this->retailer($request);

        $data = $request->validate([
            'razorpay_order_id'   => ['required', 'regex:/^order_[A-Za-z0-9]{6,40}$/'],
            'razorpay_payment_id' => ['required', 'regex:/^pay_[A-Za-z0-9]{6,40}$/'],
            'razorpay_signature'  => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        // Our own order only. The order id sent by the browser is used just to find OUR saved row.
        $payment = RazorpayPayment::where('order_id', $data['razorpay_order_id'])->where('retailer_id', $retailer->id)->firstOrFail();

        return $this->respond(function () use ($request, $retailer, $payment, $data) {
            if ($payment->status !== 'paid') {
                if (! $this->razorpay->verifyCheckoutSignature($payment->order_id, $data['razorpay_payment_id'], $data['razorpay_signature'])) {
                    Audit::log($request, 'wallet.razorpay.bad_signature', 'razorpay_payment', $payment->id, []);

                    throw new PaymentException('The payment could not be verified. If money was deducted, it will be returned by your bank.', 422, 'bad_signature');
                }

                $paidPaise = $this->razorpay->confirmPayment($payment, $data['razorpay_payment_id']);

                if ($this->razorpay->settle($payment, $data['razorpay_payment_id'], $paidPaise, 'checkout')) {
                    Audit::log($request, 'wallet.razorpay.paid', 'razorpay_payment', $payment->id, ['amount' => (float) $payment->amount, 'via' => 'checkout']);
                }
            }

            $wallet = $retailer->fresh()->wallet;

            return [
                'status'  => 'paid',
                'message' => 'Payment successful. Rs ' . number_format((float) $payment->amount, 2) . ' added to your wallet.',
                'available' => (float) ($wallet?->available_balance ?? 0),
            ];
        });
    }

    // Razorpay Checkout reported a failed attempt. Saved only to show the reason later, the order stays open for another try.
    public function failed(Request $request)
    {
        $retailer = $this->retailer($request);

        $data = $request->validate([
            'razorpay_order_id' => ['required', 'regex:/^order_[A-Za-z0-9]{6,40}$/'],
            'reason'            => ['nullable', 'string', 'max:200'],
        ]);

        RazorpayPayment::where('order_id', $data['razorpay_order_id'])
            ->where('retailer_id', $retailer->id)
            ->where('status', 'created')
            ->update(['last_error' => Str::limit((string) ($data['reason'] ?? 'Payment failed'), 190, '')]);

        return response()->json(['ok' => true]);
    }

    /* ------------------------------------------------------------------ */

    private function respond(callable $action, int $status = 200)
    {
        try {
            return response()->json($action(), $status);
        } catch (PaymentException $e) {
            return response()->json(['code' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
        }
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }
}
