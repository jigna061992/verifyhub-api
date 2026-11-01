<?php

namespace App\Console\Commands;

use App\Services\SingleVerificationService;
use Illuminate\Console\Command;

// Closes Aadhaar OTP / e-Sign checks that were left open and returns the held money to the wallet
class ExpirePendingVerifications extends Command
{
    protected $signature = 'verifications:expire-pending';

    protected $description = 'Expire stand-alone Aadhaar / e-Sign checks whose time is over and release the held wallet amount';

    public function handle(SingleVerificationService $service): int
    {
        $this->info('Expired ' . $service->expireDue() . ' check(s).');

        return self::SUCCESS;
    }
}
