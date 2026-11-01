<?php

namespace App\Console\Commands;

use App\Models\KycCase;
use App\Services\KycService;
use Illuminate\Console\Command;

// Closes KYC sessions that were left open and returns the held money to the wallet
class ExpireKycCases extends Command
{
    protected $signature = 'kyc:expire-cases';

    protected $description = 'Expire KYC cases whose time is over and release the held wallet amount';

    public function handle(KycService $kyc): int
    {
        $expired = 0;

        KycCase::where('status', 'in_progress')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($cases) use ($kyc, &$expired) {
                foreach ($cases as $case) {
                    if ($kyc->close($case, 'expired', 'This KYC session expired. Amount returned to your wallet.')) {
                        $expired++;
                    }
                }
            });

        $this->info("Expired {$expired} KYC case(s).");

        return self::SUCCESS;
    }
}
