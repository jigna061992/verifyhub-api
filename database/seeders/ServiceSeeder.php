<?php

namespace Database\Seeders;

use App\Models\VerificationService;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['code' => 'PAN',     'name' => 'PAN Verification',       'default_price' => 5,  'provider_cost' => 3],
            ['code' => 'BANK',    'name' => 'Bank Penny Drop',        'default_price' => 8,  'provider_cost' => 5],
            ['code' => 'AADHAAR', 'name' => 'Aadhaar Verification',   'default_price' => 10, 'provider_cost' => 6],
            ['code' => 'ESIGN',   'name' => 'Consent e-Sign',         'default_price' => 15, 'provider_cost' => 9],
            ['code' => 'GST',     'name' => 'GST Verification',       'default_price' => 6,  'provider_cost' => 4],
        ];

        foreach ($services as $service) {
            VerificationService::updateOrCreate(['code' => $service['code']], $service + ['enabled' => true]);
        }
    }
}