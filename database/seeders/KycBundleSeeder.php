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
                'default_price' => 32,   // single checks would cost 10 + 5 + 8 + 15 = 38
                'provider_cost' => 23,   // 6 + 3 + 5 + 9
            ]
        );
    }
}
