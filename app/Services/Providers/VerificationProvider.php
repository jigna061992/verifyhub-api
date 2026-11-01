<?php

namespace App\Services\Providers;

interface VerificationProvider
{
    /**
     * Run one check. Return ProviderResult::success() or ProviderResult::failed().
     * Throw an exception only when the provider is down/unreachable (the money is then released).
     *
     * @param string $code  PAN | BANK | GST ...
     * @param array  $input Already validated fields (pan, account_number + ifsc, gstin ...)
     */
    public function verify(string $code, array $input): ProviderResult;
}
