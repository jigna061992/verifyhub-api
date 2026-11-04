<?php

namespace App\Services;

use App\Models\Retailer;
use App\Models\RetailerPricing;
use App\Models\VerificationService;
use Illuminate\Support\Collection;

/**
 * Complete KYC has no price of its own. It is always the sum of its 4 services:
 *   Aadhaar + PAN + Bank + e-Sign
 *
 *  - default price / provider cost : stored on the Complete KYC row and refreshed by sync() whenever a service changes
 *  - price for one retailer        : the sum of THAT retailer's prices of the 4 services (custom price or default)
 *
 * So the admin never edits the Complete KYC price, and it can never drift away from the 4 services.
 */
class BundlePricing
{
    public const PARTS = ['AADHAAR', 'PAN', 'BANK', 'ESIGN'];

    public function bundleCode(): string
    {
        return strtoupper((string) config('kyc.service_code', 'COMPLETE_KYC'));
    }

    public function isBundle(VerificationService $service): bool
    {
        return $service->code === $this->bundleCode();
    }

    /** @return Collection<int, VerificationService> */
    public function parts(): Collection
    {
        return VerificationService::whereIn('code', self::PARTS)->get();
    }

    public function allPartsEnabled(): bool
    {
        $parts = $this->parts();

        return $parts->count() === count(self::PARTS) && $parts->every(fn ($s) => $s->enabled);
    }

    public function defaultPrice(): float
    {
        return round((float) $this->parts()->sum(fn ($s) => (float) $s->default_price), 2);
    }

    public function providerCost(): float
    {
        return round((float) $this->parts()->sum(fn ($s) => (float) $s->provider_cost), 2);
    }

    // What THIS retailer pays for Complete KYC
    public function priceFor(Retailer $retailer): float
    {
        $parts  = $this->parts();
        $custom = RetailerPricing::where('retailer_id', $retailer->id)
            ->whereIn('service_id', $parts->pluck('id'))
            ->pluck('custom_price', 'service_id');

        return round((float) $parts->sum(fn ($s) => (float) ($custom[$s->id] ?? $s->default_price)), 2);
    }

    // Copies the current sums into the Complete KYC row (used by the default rate card and the reports)
    public function sync(): void
    {
        $bundle = VerificationService::where('code', $this->bundleCode())->first();

        if (! $bundle) {
            return;
        }

        $bundle->update([
            'default_price' => $this->defaultPrice(),
            'provider_cost' => $this->providerCost(),
        ]);
    }
}
