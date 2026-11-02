<?php

namespace App\Exceptions;

use RuntimeException;

// A payment problem the retailer can understand. The controller turns it into a JSON error.
class PaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $errorCode = 'payment_error',
    ) {
        parent::__construct($message);
    }
}
