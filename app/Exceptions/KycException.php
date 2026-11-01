<?php

namespace App\Exceptions;

use RuntimeException;

// A problem the retailer can understand. The controller turns it into a JSON error.
class KycException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'kyc_error',
    ) {
        parent::__construct($message);
    }
}
