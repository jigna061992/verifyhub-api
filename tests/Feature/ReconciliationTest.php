<?php

namespace Tests\Feature;

use App\Models\Reconciliation;
use App\Models\Verification;
use App\Models\WalletLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedServices();
    }

    // Creates a timed-out PAN check and returns [retailer, reconciliation]
    private function heldCheck(float $balance = 100): array
    {
        $r = $this->makeRetailer($balance);

        $this->actingAsUser($r->user)->postJson('/api/retailer/verifications', [
            'service_code' => 'PAN', 'pan' => 'ABCDE1234E', 'consent' => true,
        ])->assertCreated();

        return [$r, Reconciliation::firstOrFail()];
    }

    public function test_admin_confirms_success_and_the_money_is_charged_once(): void
    {
        [$r, $rec] = $this->heldCheck();

        $this->actingAsUser($this->makeAdmin());
        $body = ['outcome' => 'success', 'name' => 'RAJESH KUMAR', 'note' => 'Seen on provider dashboard'];

        $this->postJson("/api/admin/reconciliations/{$rec->id}/resolve", $body)->assertOk();
        // Double click: refused, wallet unchanged
        $this->postJson("/api/admin/reconciliations/{$rec->id}/resolve", $body)->assertStatus(422);

        $this->assertSame(['available' => 95.0, 'reserved' => 0.0], $this->wallet($r));
        $this->assertSame('success', Verification::firstOrFail()->status);
        $this->assertSame(1, WalletLedger::where('type', 'debit')->count());
    }

    public function test_admin_marks_failed_and_the_money_is_returned_once(): void
    {
        [$r, $rec] = $this->heldCheck();

        $this->actingAsUser($this->makeAdmin());
        $body = ['outcome' => 'failed', 'note' => 'Provider has no record'];

        $this->postJson("/api/admin/reconciliations/{$rec->id}/resolve", $body)->assertOk();
        $this->postJson("/api/admin/reconciliations/{$rec->id}/resolve", $body)->assertStatus(422);

        $this->assertSame(['available' => 100.0, 'reserved' => 0.0], $this->wallet($r));
        $this->assertSame('failed', Verification::firstOrFail()->status);
    }

    public function test_note_is_required(): void
    {
        [, $rec] = $this->heldCheck();

        $this->actingAsUser($this->makeAdmin())
            ->postJson("/api/admin/reconciliations/{$rec->id}/resolve", ['outcome' => 'failed'])
            ->assertStatus(422);

        $this->assertSame('open', $rec->fresh()->status);
    }

    public function test_success_needs_the_provider_name(): void
    {
        [, $rec] = $this->heldCheck();

        $this->actingAsUser($this->makeAdmin())
            ->postJson("/api/admin/reconciliations/{$rec->id}/resolve", ['outcome' => 'success', 'note' => 'Seen on dashboard'])
            ->assertStatus(422);
    }

    public function test_retailer_cannot_resolve(): void
    {
        [$r, $rec] = $this->heldCheck();

        $this->actingAsUser($r->user)
            ->postJson("/api/admin/reconciliations/{$rec->id}/resolve", ['outcome' => 'failed', 'note' => 'I want my money'])
            ->assertStatus(403);

        $this->assertSame('open', $rec->fresh()->status);
    }
}
