<?php

namespace Tests\Feature;

use App\Models\RazorpayPayment;
use App\Services\RazorpayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

class RazorpayTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    private function order($retailer, string $orderId = 'order_TEST1', float $amount = 500): RazorpayPayment
    {
        return RazorpayPayment::create([
            'retailer_id' => $retailer->id, 'order_id' => $orderId, 'amount' => $amount,
            'amount_paise' => (int) round($amount * 100), 'currency' => 'INR', 'mode' => 'test', 'status' => 'created',
        ]);
    }

    private function webhook(string $event, string $orderId, string $paymentId, int $paise, ?string $signature = null)
    {
        $body = json_encode(['event' => $event, 'payload' => ['payment' => ['entity' => [
            'id' => $paymentId, 'order_id' => $orderId, 'amount' => $paise,
        ]]]]);

        $signature ??= hash_hmac('sha256', $body, 'test_webhook_secret');

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_checkout_signature_check(): void
    {
        $svc = app(RazorpayService::class);
        $ok  = hash_hmac('sha256', 'order_A|pay_A', 'test_key_secret');

        $this->assertTrue($svc->verifyCheckoutSignature('order_A', 'pay_A', $ok));
        $this->assertFalse($svc->verifyCheckoutSignature('order_A', 'pay_B', $ok));
        $this->assertFalse($svc->verifyCheckoutSignature('order_A', 'pay_A', ''));
    }

    public function test_webhook_with_bad_signature_is_rejected_and_wallet_unchanged(): void
    {
        $r = $this->makeRetailer();
        $this->order($r);

        $this->webhook('payment.captured', 'order_TEST1', 'pay_1', 50000, 'bad-signature')->assertStatus(401);

        $this->assertSame(0.0, $this->wallet($r)['available']);
    }

    public function test_webhook_credits_the_wallet_only_once_even_if_sent_twice(): void
    {
        $r = $this->makeRetailer();
        $this->order($r);

        $this->webhook('payment.captured', 'order_TEST1', 'pay_1', 50000)->assertOk();
        $this->webhook('order.paid', 'order_TEST1', 'pay_1', 50000)->assertOk();

        $this->assertSame(500.0, $this->wallet($r)['available']);
        $this->assertSame('paid', RazorpayPayment::firstOrFail()->status);
    }

    public function test_wrong_amount_is_not_credited(): void
    {
        $r = $this->makeRetailer();
        $this->order($r);

        $this->webhook('payment.captured', 'order_TEST1', 'pay_1', 100)->assertOk();

        $this->assertSame(0.0, $this->wallet($r)['available']);
        $this->assertSame('created', RazorpayPayment::firstOrFail()->status);
    }

    public function test_unknown_order_is_ignored(): void
    {
        $this->webhook('payment.captured', 'order_NOT_OURS', 'pay_9', 50000)->assertOk();
    }
}
