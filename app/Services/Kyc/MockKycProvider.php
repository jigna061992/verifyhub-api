<?php

namespace App\Services\Kyc;

use App\Services\Providers\ProviderResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fake provider for development. No internet and no real data needed.
 *
 * Aadhaar : number ends with 0 -> not found | ends with 9 -> provider down | otherwise a RANDOM 6 digit OTP is made.
 *            The OTP is kept only as a hash in the cache for a short time, can be used once, and is replaced when a new OTP is sent.
 *            There is no real SMS: the tester sees the OTP on the screen (test mode) and in storage/logs/laravel.log.
 * PAN     : ends with Z -> invalid | E -> provider down | M -> different name | L -> not linked with Aadhaar | otherwise valid
 * Bank    : account ends with 0 -> invalid | 9 -> provider down | 7 -> different name | otherwise valid
 * e-Sign  : always starts. Use the "simulate" button in the app to finish it (development only).
 *
 * The matching name is always TEST USER.
 */
class MockKycProvider implements KycProvider
{
    // How long a generated OTP stays valid (seconds)
    private function otpTtl(): int
    {
        return (int) config('kyc.otp_ttl_seconds', 600);
    }

    private function otpKey(string $transactionId): string
    {
        return 'mock_kyc:aadhaar_otp:' . $transactionId;
    }

    // Only a hash is stored, never the OTP itself
    private function otpHash(string $transactionId, string $otp): string
    {
        return hash_hmac('sha256', $transactionId . '|' . $otp, (string) config('app.key'));
    }

    public function aadhaarSendOtp(string $aadhaarNumber): ProviderResult
    {
        $this->pause();
        $ref = 'MOCK-TXN-' . strtoupper(Str::random(10));

        return match (substr($aadhaarNumber, -1)) {
            '0'     => ProviderResult::failed('Aadhaar number not found.', $ref),
            '9'     => throw new \RuntimeException('Mock provider timeout.'),
            default => $this->issueOtp($ref),
        };
    }

    // Makes the OTP, keeps its hash, and hands the OTP back as "dev_otp" (it plays the role of the SMS)
    private function issueOtp(string $ref): ProviderResult
    {
        $otp = (string) random_int(100000, 999999);

        Cache::put($this->otpKey($ref), $this->otpHash($ref, $otp), now()->addSeconds($this->otpTtl()));

        if (! app()->isProduction()) {
            Log::info("[MOCK SMS] Aadhaar OTP for {$ref} is {$otp}");
        }

        return ProviderResult::success(['otp_sent' => true, 'dev_otp' => $otp], $ref);
    }

    public function aadhaarVerifyOtp(string $transactionId, string $otp): ProviderResult
    {
        $this->pause();

        $expected = Cache::get($this->otpKey($transactionId));

        if ($expected === null) {
            return ProviderResult::failed('The OTP has expired. Please request a new OTP.', $transactionId);
        }

        if (! hash_equals($expected, $this->otpHash($transactionId, $otp))) {
            return ProviderResult::failed('Incorrect OTP.', $transactionId);
        }

        Cache::forget($this->otpKey($transactionId)); // one OTP, one use

        return ProviderResult::success(['name' => 'TEST USER'], $transactionId);
    }

    public function pan(string $pan): ProviderResult
    {
        $this->pause();
        $ref = 'MOCK-' . strtoupper(Str::random(10));

        return match (substr($pan, -1)) {
            'Z'     => ProviderResult::failed('PAN not found or invalid.', $ref),
            'E'     => throw new \RuntimeException('Mock provider timeout.'),
            'M'     => ProviderResult::success(['name' => 'RAMESH KUMAR', 'pan_status' => 'VALID', 'aadhaar_linked' => true], $ref),
            'L'     => ProviderResult::success(['name' => 'TEST USER', 'pan_status' => 'VALID', 'aadhaar_linked' => false], $ref),
            default => ProviderResult::success(['name' => 'TEST USER', 'pan_status' => 'VALID', 'aadhaar_linked' => true], $ref),
        };
    }

    public function bank(string $accountNumber, string $ifsc): ProviderResult
    {
        $this->pause();
        $ref = 'MOCK-' . strtoupper(Str::random(10));

        return match (substr($accountNumber, -1)) {
            '0'     => ProviderResult::failed('Bank account not found or invalid.', $ref),
            '9'     => throw new \RuntimeException('Mock provider timeout.'),
            '7'     => ProviderResult::success(['account_holder' => 'SOME OTHER PERSON', 'account_status' => 'ACTIVE'], $ref),
            default => ProviderResult::success(['account_holder' => 'TEST USER', 'account_status' => 'ACTIVE'], $ref),
        };
    }

    public function esignStart(array $document): ProviderResult
    {
        $this->pause();

        // No real signing page in the mock, so there is no link
        return ProviderResult::success(['sign_url' => null], 'MOCK-ESIGN-' . strtoupper(Str::random(10)));
    }

    private function pause(): void
    {
        usleep(300000); // pretend the network call takes a moment
    }
}
