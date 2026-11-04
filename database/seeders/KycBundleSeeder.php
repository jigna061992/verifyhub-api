<?php

namespace Database\Seeders;

use App\Models\VerificationService;
use Illuminate\Database\Seeder;

class KycBundleSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate: an admin edit of the price is never overwritten when the seeder runs again
        VerificationService::firstOrCreate(
            ['code' => config('kyc.service_code', 'COMPLETE_KYC')],
            [
                'name'          => 'Complete KYC (Aadhaar + PAN + Bank + e-Sign)',
                'provider'      => 'mock',
                'enabled'       => true,
                'default_price' => 0,    // set below: always the sum of the 4 services (see BundlePricing)
                'provider_cost' => 0,    // set below: always the sum of the 4 services
            ]
        );

        // Complete KYC price and cost = Aadhaar + PAN + Bank + e-Sign (run ServiceSeeder first)
        app(\App\Services\BundlePricing::class)->sync();
    }
}
