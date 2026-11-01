<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(string $message = 'Insufficient wallet balance.')
    {
        parent::__construct($message);
    }
}
