<?php

return [
    // Which provider is used. Add a real one (Signzy, Cashfree...) in "drivers" and set it in .env
    'driver' => env('KYC_PROVIDER', 'mock'),

    'drivers' => [
        'mock' => \App\Services\Kyc\MockKycProvider::class,
    ],

    // The row in verification_services that holds the bundle price (see KycBundleSeeder)
    'service_code' => 'COMPLETE_KYC',

    // A KYC session must be finished within this time, otherwise it expires and the money is returned
    'case_ttl_minutes' => 120,

    // Failed tries allowed per step (wrong OTP, invalid PAN, name mismatch...). After that the KYC fails.
    'max_attempts' => 3,

    // Aadhaar OTP limits
    'otp_max_sends' => 3,
    'otp_cooldown_seconds' => 30,
    'otp_ttl_seconds' => 600,

    // Minimum name match score (0-100) against the name on Aadhaar
    'name_match' => [
        'pan'  => 80,
        'bank' => 70,
    ],

    // PAN must be linked with Aadhaar to pass
    'require_pan_aadhaar_link' => true,

    // Shared secret used to verify the e-Sign provider webhook (HMAC SHA-256 of the raw body)
    'esign_webhook_secret' => env('KYC_ESIGN_WEBHOOK_SECRET'),

    'purposes' => [
        'account_opening'  => 'Account opening',
        'sim_activation'   => 'SIM activation',
        'loan_application' => 'Loan application',
        'general_kyc'      => 'General KYC',
    ],

    // Consent shown to the customer before anything is verified. Change the text => change the version.
    'consent' => [
        'version' => 'v1',
        'text'    => "I, {customer_name}, authorise {retailer} and VerifyHub to verify my identity for the purpose of {purpose}. "
            . "This includes my Aadhaar (using a one time password sent to my registered mobile number), my PAN and my bank account details, "
            . "checked through authorised verification providers. I understand that my full Aadhaar number is not stored, "
            . "that only masked identifiers and the outcome of the checks are kept, and that I can withdraw this consent by contacting {retailer}.",
    ],
];
