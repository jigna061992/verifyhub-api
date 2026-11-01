<?php

namespace App\Services\Providers;

use Illuminate\Support\Str;

/**
 * Fake provider for development. No internet and no real data needed.
 *
 * Test rules:
 *   PAN  : ends with Z -> invalid | ends with E -> provider down | anything else -> valid
 *   BANK : account ends with 0 -> invalid | ends with 9 -> provider down | anything else -> valid
 *   GST  : GSTIN ends with 0 -> invalid | anything else -> valid
 */
class MockProvider implements VerificationProvider
{
    public function verify(string $code, array $input): ProviderResult
    {
        usleep(300000); // pretend the network call takes a moment

        $ref = 'MOCK-' . strtoupper(Str::random(10));

        return match ($code) {
            'PAN'  => $this->pan($input['pan'], $ref),
            'BANK' => $this->bank($input['account_number'], $ref),
            'GST'  => $this->gst($input['gstin'], $ref),
            default => throw new \RuntimeException("Mock provider does not support {$code}."),
        };
    }

    private function pan(string $pan, string $ref): ProviderResult
    {
        return match (substr($pan, -1)) {
            'Z'     => ProviderResult::failed('PAN not found or invalid.', $ref),
            'E'     => throw new \RuntimeException('Mock provider timeout.'),
            default => ProviderResult::success(['name' => 'TEST USER', 'pan_status' => 'VALID'], $ref),
        };
    }

    private function bank(string $account, string $ref): ProviderResult
    {
        return match (substr($account, -1)) {
            '0'     => ProviderResult::failed('Bank account not found or invalid.', $ref),
            '9'     => throw new \RuntimeException('Mock provider timeout.'),
            default => ProviderResult::success(['account_holder' => 'TEST USER', 'account_status' => 'ACTIVE'], $ref),
        };
    }

    private function gst(string $gstin, string $ref): ProviderResult
    {
        return str_ends_with($gstin, '0')
            ? ProviderResult::failed('GSTIN not found or invalid.', $ref)
            : ProviderResult::success(['legal_name' => 'TEST TRADERS PVT LTD', 'gst_status' => 'ACTIVE'], $ref);
    }
}
