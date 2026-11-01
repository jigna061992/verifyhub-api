<?php

return [
    // Which provider is used. Add a real one (Cashfree, Signzy...) in "drivers" and set it in .env
    'driver' => env('VERIFICATION_PROVIDER', 'mock'),

    'drivers' => [
        'mock' => \App\Services\Providers\MockProvider::class,
    ],

    // Aadhaar OTP and e-Sign checks (they use the provider set in config/kyc.php: KYC_PROVIDER)
    'sessions' => [
        'aadhaar_ttl_minutes' => 15,    // OTP must be completed within this time, otherwise the money is returned
        'esign_ttl_minutes'   => 120,   // the customer must sign within this time
        'max_attempts'        => 3,     // wrong OTP tries
        'otp_max_sends'       => 3,
        'otp_cooldown_seconds' => 30,
    ],

    // Consent text of the single checks. Change the text => change the version.
    'consent' => [
        'aadhaar' => [
            'version' => 'v1',
            'text'    => "I, {customer_name}, authorise {retailer} and VerifyHub to verify my Aadhaar using a one time password sent to my registered mobile number, "
                . "for the purpose of {purpose}. I understand that my full Aadhaar number is not stored, that only the last 4 digits and the outcome of the check are kept, "
                . "and that I can withdraw this consent by contacting {retailer}.",
        ],
        'esign' => [
            'version' => 'v1',
            'text'    => "I, {customer_name}, agree to receive a consent letter from {retailer} on VerifyHub and to sign it electronically (e-Sign) "
                . "for the purpose of {purpose}. I understand that only the signed letter and the outcome of the signing are kept.",
        ],
    ],

    // The letter the customer signs in the stand-alone e-Sign check
    'esign_letter' => "CONSENT LETTER\nReference: {reference}\nDate: {date}\n\n"
        . "I, {customer_name}, give my consent to {retailer} and VerifyHub to collect and verify my identity details "
        . "for the purpose of {purpose}.\n\n"
        . "I understand that VerifyHub keeps only masked identifiers and the outcome of the checks, and that I can withdraw this consent by contacting {retailer}.\n"
        . "Consent version: {version}",
];
