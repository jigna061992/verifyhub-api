<?php

namespace Tests\Feature;

use App\Models\VerificationService;
use App\Services\BundlePricing;
use App\Services\VerificationRunner;
use Database\Seeders\KycBundleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

// Complete KYC price = Aadhaar + PAN + Bank + e-Sign (never typed by hand). GST service does not exist any more.
class BundlePricingTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedServices();
        $this->seed(KycBundleSeeder::class);
    }

    private function bundle(): VerificationService
    {
        return $this->service('COMPLETE_KYC');
    }

    public function test_complete_kyc_price_is_the_sum_of_the_four_services(): void
    {
        $parts = collect(['AADHAAR', 'PAN', 'BANK', 'ESIGN'])->map(fn ($c) => $this->service($c));

        $this->assertSame((float) $parts->sum('default_price'), (float) $this->bundle()->default_price);
        $this->assertSame((float) $parts->sum('provider_cost'), (float) $this->bundle()->provider_cost);
    }

    public function test_changing_a_service_price_changes_complete_kyc(): void
    {
        $pan  = $this->service('PAN');
        $sum  = fn () => (float) $this->bundle()->default_price;
        $before = $sum();

        $this->actingAsUser($this->makeAdmin())
            ->putJson('/api/admin/services', ['services' => [
                ['id' => $pan->id, 'default_price' => (float) $pan->default_price + 2, 'provider_cost' => (float) $pan->provider_cost],
            ]])->assertOk();

        $this->assertSame($before + 2, $sum());
    }

    public function test_admin_cannot_set_the_complete_kyc_price_by_hand(): void
    {
        $bundle = $this->bundle();
        $admin  = $this->makeAdmin();

        $this->actingAsUser($admin)
            ->putJson("/api/admin/services/{$bundle->id}", ['default_price' => 1])
            ->assertStatus(422);

        // In the bulk save the Complete KYC row is ignored
        $before = (float) $bundle->default_price;
        $this->putJson('/api/admin/services', ['services' => [
            ['id' => $bundle->id, 'default_price' => 1, 'provider_cost' => 1],
        ]])->assertOk();

        $this->assertSame($before, (float) $this->bundle()->default_price);
    }

    public function test_retailer_custom_price_of_a_service_changes_only_that_retailers_complete_kyc(): void
    {
        $a = $this->makeRetailer(100);
        $b = $this->makeRetailer(100);
        $pan = $this->service('PAN');

        $this->actingAsUser($this->makeAdmin())
            ->putJson("/api/admin/retailers/{$a->id}/pricing/{$pan->id}", ['custom_price' => (float) $pan->default_price + 4])
            ->assertOk();

        $runner = app(VerificationRunner::class);
        $default = (float) $this->bundle()->default_price;

        $this->assertSame($default + 4, $runner->priceFor($a, $this->bundle()));
        $this->assertSame($default, $runner->priceFor($b, $this->bundle()));
    }

    public function test_retailer_price_of_complete_kyc_cannot_be_set_by_hand(): void
    {
        $r = $this->makeRetailer();

        $this->actingAsUser($this->makeAdmin())
            ->putJson("/api/admin/retailers/{$r->id}/pricing/{$this->bundle()->id}", ['custom_price' => 10])
            ->assertStatus(422);
    }

    public function test_complete_kyc_is_not_available_when_one_of_its_services_is_off(): void
    {
        $this->assertTrue(app(BundlePricing::class)->allPartsEnabled());

        $this->service('ESIGN')->update(['enabled' => false]);

        $this->assertFalse(app(BundlePricing::class)->allPartsEnabled());
    }

    public function test_gst_service_is_gone(): void
    {
        $this->assertNull(VerificationService::where('code', 'GST')->first());

        $r = $this->makeRetailer(100);

        $res = $this->actingAsUser($r->user)->getJson('/api/retailer/services')->assertOk();
        $this->assertNotContains('GST', collect($res->json())->pluck('code')->all());

        $this->postJson('/api/retailer/verifications', ['service_code' => 'GST', 'gstin' => '24ABCPK1234L1Z5', 'consent' => true])
            ->assertStatus(422);
    }
}
