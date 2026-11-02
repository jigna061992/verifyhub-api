<?php

namespace Tests\Feature;

use App\Models\Reconciliation;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

// Uses the mock provider: PAN ending Z = invalid, PAN ending E = provider timeout, anything else = valid
class VerificationFlowTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedServices(); // PAN price Rs 5
    }

    private function pan(string $pan, array $extra = [])
    {
        return $this->postJson('/api/retailer/verifications', array_merge(
            ['service_code' => 'PAN', 'pan' => $pan, 'consent' => true],
            $extra
        ));
    }

    public function test_successful_check_charges_the_price(): void
    {
        $r = $this->makeRetailer(100);

        $this->actingAsUser($r->user);
        $this->pan('ABCDE1234F')->assertCreated()->assertJsonPath('status', 'success');

        $this->assertSame(['available' => 95.0, 'reserved' => 0.0], $this->wallet($r));
    }

    public function test_invalid_pan_is_not_charged(): void
    {
        $r = $this->makeRetailer(100);

        $this->actingAsUser($r->user);
        $this->pan('ABCDE1234Z')->assertCreated()->assertJsonPath('status', 'failed');

        $this->assertSame(['available' => 100.0, 'reserved' => 0.0], $this->wallet($r));
    }

    public function test_consent_is_required(): void
    {
        $r = $this->makeRetailer(100);

        $this->actingAsUser($r->user);
        $this->pan('ABCDE1234F', ['consent' => false])->assertStatus(422);

        $this->assertSame(100.0, $this->wallet($r)['available']);
        $this->assertSame(0, Verification::count());
    }

    public function test_insufficient_balance_is_refused_without_creating_a_charge(): void
    {
        $r = $this->makeRetailer(2);

        $this->actingAsUser($r->user);
        $this->pan('ABCDE1234F')->assertStatus(422)->assertJsonPath('code', 'insufficient_balance');

        $this->assertSame(['available' => 2.0, 'reserved' => 0.0], $this->wallet($r));
    }

    public function test_bad_pan_format_is_rejected(): void
    {
        $r = $this->makeRetailer(100);

        $this->actingAsUser($r->user);
        $this->pan('12345')->assertStatus(422);
    }

    public function test_provider_timeout_holds_the_money_and_opens_reconciliation(): void
    {
        $r = $this->makeRetailer(100);

        $this->actingAsUser($r->user);
        $this->pan('ABCDE1234E')->assertCreated()->assertJsonPath('status', 'pending');

        // Money stays on hold, nothing is charged or returned yet
        $this->assertSame(['available' => 95.0, 'reserved' => 5.0], $this->wallet($r));
        $this->assertSame(1, Reconciliation::where('status', 'open')->count());
    }

    public function test_full_pan_number_is_never_returned_or_stored_in_plain_text(): void
    {
        $r = $this->makeRetailer(100);

        $this->actingAsUser($r->user);
        $res = $this->pan('ABCDE1234F');

        $this->assertStringNotContainsString('ABCDE1234F', $res->getContent());

        $v = Verification::firstOrFail();
        $this->assertStringNotContainsString('ABCDE1234F', (string) $v->input_masked);
        $this->assertStringNotContainsString('ABCDE1234F', (string) json_encode($v->getRawOriginal('meta')));
    }

    public function test_retailer_sees_only_own_history(): void
    {
        $a = $this->makeRetailer(100);
        $b = $this->makeRetailer(100);

        $this->actingAsUser($a->user);
        $this->pan('ABCDE1234F')->assertCreated();

        $this->actingAsUser($b->user);
        $res = $this->getJson('/api/retailer/verifications')->assertOk();

        $this->assertCount(0, $res->json('data'));
    }
}
