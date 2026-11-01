<?php

namespace App\Services\Providers;

final class ProviderResult
{
    private function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $providerRef = null,
        public readonly ?string $error = null,
    ) {
    }

    // The document is valid. $data is what we show to the retailer (name, status...)
    public static function success(array $data, ?string $providerRef = null): self
    {
        return new self(true, $data, $providerRef);
    }

    // The provider answered, but the document is invalid / not found
    public static function failed(string $error, ?string $providerRef = null): self
    {
        return new self(false, [], $providerRef, $error);
    }
}
