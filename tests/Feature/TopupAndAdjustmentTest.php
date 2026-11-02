<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\WalletTopupRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

class TopupAndAdjustmentTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    private function submitTopup($retailer, string $utr = 'UTR123456', float $amount = 500)
    {
        return $this->actingAsUser($retailer->user)->postJson('/api/retailer/topups', [
            'amount' => $amount, 'method' => 'UPI', 'utr' => $utr,
        ]);
    }

    public function test_retailer_submits_a_topup_request_and_wallet_is_not_changed_yet(): void
    {
        $r = $this->makeRetailer();

        $this->submitTopup($r)->assertCreated()->assertJsonPath('status', 'pending');

        $this->assertSame(0.0, $this->wallet($r)['available']);
    }

    public function test_same_utr_cannot_be_used_twice(): void
    {
        $r = $this->makeRetailer();

        $this->submitTopup($r)->assertCreated();
        $this->submitTopup($r)->assertStatus(422);
    }

    public function test_amount_limits(): void
    {
        $r = $this->makeRetailer();

        $this->submitTopup($r, 'UTR000001', 50)->assertStatus(422);
        $this->submitTopup($r, 'UTR000002', 500000)->assertStatus(422);
    }

    public function test_admin_approval_credits_once_even_on_double_click(): void
    {
        $r = $this->makeRetailer();
        $this->submitTopup($r)->assertCreated();
        $topup = WalletTopupRequest::firstOrFail();

        $this->actingAsUser($this->makeAdmin());
        $this->postJson("/api/admin/topups/{$topup->id}/approve")->assertOk();
        $this->postJson("/api/admin/topups/{$topup->id}/approve")->assertStatus(422);

        $this->assertSame(500.0, $this->wallet($r)['available']);
        $this->assertSame(1, AuditLog::where('action', 'topup.approved')->count());
    }

    public function test_rejected_topup_does_not_credit(): void
    {
        $r = $this->makeRetailer();
        $this->submitTopup($r)->assertCreated();
        $topup = WalletTopupRequest::firstOrFail();

        $this->actingAsUser($this->makeAdmin())
            ->postJson("/api/admin/topups/{$topup->id}/reject", ['reason' => 'UTR not found in bank statement'])
            ->assertOk();

        $this->assertSame(0.0, $this->wallet($r)['available']);
    }

    public function test_adjustment_with_the_same_request_key_applies_once_and_is_audited_once(): void
    {
        $r    = $this->makeRetailer(100);
        $body = ['type' => 'credit', 'amount' => 50, 'reason' => 'Goodwill credit', 'request_key' => '22222222-2222-4222-8222-222222222222'];

        $this->actingAsUser($this->makeAdmin());
        $this->postJson("/api/admin/wallets/{$r->id}/adjust", $body)->assertCreated();   // first time: 201
        $this->postJson("/api/admin/wallets/{$r->id}/adjust", $body)->assertOk();        // same key again: 200, nothing changes

        $this->assertSame(150.0, $this->wallet($r)['available']);
        $this->assertSame(1, AuditLog::where('action', 'wallet.adjusted')->count());
    }

    public function test_deduction_larger_than_balance_is_refused(): void
    {
        $r = $this->makeRetailer(10);

        $this->actingAsUser($this->makeAdmin())
            ->postJson("/api/admin/wallets/{$r->id}/adjust", [
                'type' => 'debit', 'amount' => 500, 'reason' => 'Correction', 'request_key' => '33333333-3333-4333-8333-333333333333',
            ])->assertStatus(422);

        $this->assertSame(10.0, $this->wallet($r)['available']);
    }

    public function test_retailer_cannot_adjust_wallets(): void
    {
        $r = $this->makeRetailer(10);

        $this->actingAsUser($r->user)
            ->postJson("/api/admin/wallets/{$r->id}/adjust", [
                'type' => 'credit', 'amount' => 500, 'reason' => 'Give me money', 'request_key' => '44444444-4444-4444-8444-444444444444',
            ])->assertStatus(403);
    }
}