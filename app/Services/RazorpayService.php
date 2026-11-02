<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\RazorpayPayment;
use App\Models\WalletTopupRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Razorpay online top-up.
 *
 *  1. createOrder()   the server creates the order (the amount is decided here, never by the browser)
 *  2. Checkout        the customer pays in the Razorpay popup (test mode = fake money)
 *  3. verify/webhook  the signature is checked, the payment is confirmed with Razorpay, then settle() credits the wallet ONCE
 *
 * The key secret never leaves the server. Only the key id (rzp_test_...) is sent to the browser.
 */
class RazorpayService
{
    public function __construct(private WalletService $wallets)
    {
    }

    public function isConfigured(): bool
    {
        return (string) config('services.razorpay.key_id') !== '' && (string) config('services.razorpay.key_secret') !== '';
    }

    // test | live, decided by the key itself
    public function mode(): string
    {
        return str_starts_with((string) config('services.razorpay.key_id'), 'rzp_test_') ? 'test' : 'live';
    }

    // Online top-up is available only with keys that are allowed in this environment
    public function isEnabled(): bool
    {
        return $this->isConfigured() && ($this->mode() === 'test' || (bool) config('services.razorpay.allow_live'));
    }

    public function ensureUsable(): void
    {
        if (! $this->isConfigured()) {
            throw new PaymentException('Online payment is not set up yet.', 503, 'not_configured');
        }

        if ($this->mode() === 'live' && ! config('services.razorpay.allow_live')) {
            throw new PaymentException('Live payments are switched off. Use Razorpay TEST keys (rzp_test_...) while developing.', 503, 'live_blocked');
        }
    }

    /* ------------------------------------------------------------------
     | Razorpay API
     * ------------------------------------------------------------------ */

    /** @return array order entity (id, amount, currency ...) */
    public function createOrder(float $amount, string $receipt, array $notes = []): array
    {
        $this->ensureUsable();

        return $this->call('create the order', fn () => $this->http()->post('/orders', [
            'amount'   => (int) round($amount * 100),
            'currency' => 'INR',
            'receipt'  => substr($receipt, 0, 40),
            'notes'    => $notes,
        ]));
    }

    /** @return array payment entity (id, order_id, amount, status ...) */
    public function fetchPayment(string $paymentId): array
    {
        $this->ensureUsable();

        return $this->call('check the payment', fn () => $this->http()->get('/payments/' . $paymentId));
    }

    public function capturePayment(string $paymentId, int $amountPaise): array
    {
        $this->ensureUsable();

        return $this->call('capture the payment', fn () => $this->http()->post("/payments/{$paymentId}/capture", ['amount' => $amountPaise, 'currency' => 'INR']));
    }

    /* ------------------------------------------------------------------
     | Signatures
     * ------------------------------------------------------------------ */

    // Checkout returns razorpay_signature = HMAC_SHA256(order_id + "|" + payment_id, key_secret)
    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    // Webhook header X-Razorpay-Signature = HMAC_SHA256(raw body, webhook secret)
    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        if ($secret === '' || ! $signature) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /* ------------------------------------------------------------------
     | Confirm and settle
     * ------------------------------------------------------------------ */

    /**
     * Asks Razorpay for the payment itself (the browser is never trusted alone) and checks that it belongs to our order,
     * has the right amount and is captured. An "authorized" payment is captured here.
     *
     * @return int paid amount in paise
     */
    public function confirmPayment(RazorpayPayment $row, string $paymentId): int
    {
        $payment = $this->fetchPayment($paymentId);

        if (($payment['order_id'] ?? null) !== $row->order_id) {
            throw new PaymentException('This payment does not belong to this order.', 422, 'order_mismatch');
        }

        if (($payment['status'] ?? null) === 'authorized') {
            $payment = $this->capturePayment($paymentId, (int) $row->amount_paise);
        }

        if (($payment['status'] ?? null) !== 'captured') {
            throw new PaymentException('The payment is not completed yet. If money was deducted it will be added to your wallet automatically.', 409, 'not_captured');
        }

        return (int) ($payment['amount'] ?? 0);
    }

    /**
     * Credits the wallet exactly once for a paid order. Safe to call from the checkout call AND the webhook, in any order.
     * A top-up row is created as "approved", so the payment shows in the normal top-up list and the admin list.
     *
     * @return bool true when THIS call credited the wallet
     */
    public function settle(RazorpayPayment $payment, string $paymentId, int $paidPaise, string $via): bool
    {
        $credited = DB::transaction(function () use ($payment, $paymentId, $paidPaise, $via) {
            $row = RazorpayPayment::lockForUpdate()->findOrFail($payment->id);

            if ($row->status === 'paid') {
                return false;
            }

            if ($paidPaise !== (int) $row->amount_paise) {
                throw new PaymentException('The paid amount does not match the order.', 422, 'amount_mismatch');
            }

            $topup = WalletTopupRequest::create([
                'retailer_id' => $row->retailer_id,
                'amount'      => $row->amount,
                'method'      => 'RAZORPAY',
                'utr'         => $paymentId,
                'status'      => 'approved',
                'approved_at' => now(),
            ]);

            $this->wallets->credit(
                $row->retailer_id,
                $row->amount,
                WalletService::razorpayReference($row->order_id),
                "Wallet top-up #{$topup->id} (Razorpay {$row->mode} payment {$paymentId})"
            );

            $row->update([
                'status'           => 'paid',
                'payment_id'       => $paymentId,
                'topup_request_id' => $topup->id,
                'settled_via'      => $via,
                'last_error'       => null,
                'paid_at'          => now(),
            ]);

            return true;
        });

        // Notify only when THIS call credited the wallet (the checkout call and the webhook can both arrive)
        if ($credited) {
            app(NotificationService::class)->walletCreditedOnline((int) $payment->retailer_id, (float) $payment->amount);
        }

        return $credited;
    }

    /* ------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function http()
    {
        return Http::baseUrl((string) config('services.razorpay.api_url'))
            ->withBasicAuth((string) config('services.razorpay.key_id'), (string) config('services.razorpay.key_secret'))
            ->acceptJson()
            ->timeout(15);
    }

    // Runs one Razorpay request. Network problems and error answers both become a clean PaymentException.
    private function call(string $action, callable $request): array
    {
        try {
            $response = $request();

            if ($response->failed()) {
                // Log only the status and Razorpay's error code. Never the keys, never the full body.
                Log::warning('Razorpay request failed', [
                    'action' => $action,
                    'status' => $response->status(),
                    'code'   => $response->json('error.code'),
                ]);

                $message = $response->status() === 401
                    ? 'Razorpay rejected the keys. Check RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.'
                    : "Could not {$action}. Please try again in a moment.";

                throw new PaymentException($message, $response->status() === 401 ? 503 : 502, 'gateway_error');
            }

            return (array) $response->json();
        } catch (PaymentException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Razorpay request error', ['action' => $action, 'error' => get_class($e)]);

            throw new PaymentException('Payment gateway is not responding. Please try again in a moment.', 502, 'gateway_down');
        }
    }
}
