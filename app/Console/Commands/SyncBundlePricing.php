<?php

namespace App\Console\Commands;

use App\Models\VerificationService;
use App\Services\BundlePricing;
use Illuminate\Console\Command;

// Recalculates the Complete KYC price and provider cost from Aadhaar + PAN + Bank + e-Sign
class SyncBundlePricing extends Command
{
    protected $signature = 'kyc:sync-bundle-pricing {--dry-run : Only show the numbers, do not save}';

    protected $description = 'Set Complete KYC price and provider cost = sum of Aadhaar, PAN, Bank and e-Sign';

    public function handle(BundlePricing $bundle): int
    {
        $row = VerificationService::where('code', $bundle->bundleCode())->first();

        if (! $row) {
            $this->error('Complete KYC service row not found. Run: php artisan db:seed --class=KycBundleSeeder');

            return self::FAILURE;
        }

        $parts = $bundle->parts();

        if ($parts->count() !== count(BundlePricing::PARTS)) {
            $missing = array_diff(BundlePricing::PARTS, $parts->pluck('code')->all());
            $this->error('Missing service(s): ' . implode(', ', $missing));

            return self::FAILURE;
        }

        $this->table(
            ['Service', 'Price', 'Provider cost', 'Enabled'],
            $parts->map(fn ($s) => [$s->code, $s->default_price, $s->provider_cost, $s->enabled ? 'yes' : 'NO'])->all()
        );

        $price = $bundle->defaultPrice();
        $cost  = $bundle->providerCost();

        $this->line("Complete KYC  old: {$row->default_price} / {$row->provider_cost}   new: {$price} / {$cost}");

        if (! $bundle->allPartsEnabled()) {
            $this->warn('One of the 4 services is disabled, so Complete KYC is not available to retailers.');
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run, nothing saved.');

            return self::SUCCESS;
        }

        $bundle->sync();
        $this->info('Complete KYC price updated.');

        return self::SUCCESS;
    }
}
