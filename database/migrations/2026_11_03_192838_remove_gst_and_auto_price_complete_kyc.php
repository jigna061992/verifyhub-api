<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. GST service is removed. Delete it when nothing refers to it, otherwise only switch it off (old rows keep their link).
        $gst = DB::table('verification_services')->where('code', 'GST')->first();

        if ($gst) {
            DB::table('retailer_pricings')->where('service_id', $gst->id)->delete();

            $used = DB::table('verifications')->where('service_id', $gst->id)->exists()
                || DB::table('reconciliations')->where('service_id', $gst->id)->exists();

            if ($used) {
                DB::table('verification_services')->where('id', $gst->id)->update(['enabled' => false]);
            } else {
                DB::table('verification_services')->where('id', $gst->id)->delete();
            }
        }

        // 2. Complete KYC price and cost = sum of Aadhaar + PAN + Bank + e-Sign. Hand made custom prices of it are removed.
        $bundleCode = strtoupper((string) config('kyc.service_code', 'COMPLETE_KYC'));
        $bundle     = DB::table('verification_services')->where('code', $bundleCode)->first();

        if ($bundle) {
            DB::table('retailer_pricings')->where('service_id', $bundle->id)->delete();

            $parts = DB::table('verification_services')->whereIn('code', ['AADHAAR', 'PAN', 'BANK', 'ESIGN'])->get();

            DB::table('verification_services')->where('id', $bundle->id)->update([
                'default_price' => round((float) $parts->sum('default_price'), 2),
                'provider_cost' => round((float) $parts->sum('provider_cost'), 2),
            ]);
        }
    }

    public function down(): void
    {
        // A removed service and old hand made prices cannot be brought back automatically.
    }
};
