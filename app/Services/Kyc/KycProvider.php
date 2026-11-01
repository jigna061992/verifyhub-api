<?php

namespace App\Services\Kyc;

use App\Services\Providers\ProviderResult;

/**
 * What a real KYC provider adapter must do. Return ProviderResult::success() or ::failed().
 * Throw an exception only when the provider is down or unreachable (the step can then be tried again).
 *
 * Never put the Aadhaar number, OTP or any other input into an exception message or a log line.
 */
interface KycProvider
{
    // Send an OTP to the mobile linked with this Aadhaar. providerRef = transaction id used to verify the OTP.
    public function aadhaarSendOtp(string $aadhaarNumber): ProviderResult;

    // data: ['name' => ...]
    public function aadhaarVerifyOtp(string $transactionId, string $otp): ProviderResult;

    // data: ['name' => ..., 'pan_status' => ..., 'aadhaar_linked' => bool]
    public function pan(string $pan): ProviderResult;

    // Penny drop. data: ['account_holder' => ..., 'account_status' => ...]
    public function bank(string $accountNumber, string $ifsc): ProviderResult;

    /**
     * Start e-Sign of the consent letter.
     * $document: reference, customer_name, customer_mobile, letter_text, letter_hash
     * providerRef = signing request id. data: ['sign_url' => string|null]
     * The result comes later by webhook (see KycWebhookController).
     */
    public function esignStart(array $document): ProviderResult;
}
