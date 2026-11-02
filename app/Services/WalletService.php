<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use App\Models\WalletLedger;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The ONLY place that changes wallet balances.
 * Every change writes a ledger row, and the unique "reference" makes it idempotent:
 * calling the same method twice with the same reference changes the wallet only once.
 *
 *  credit  : available +amount                       (top-up approved)
 *  reserve : available -amount, reserved +amount     (hold money before a verification)
 *  debit   : reserved  -amount                       (verification succeeded, money is spent)
 *  release : reserved  -amount, available +amount    (verification failed, money comes back)
 *  reversal: available -amount                       (admin manual deduction, a NEW compensating entry)
 */
class WalletService
{
    public static function topupReference(int $topupId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "verifyhub:topup:{$topupId}")->toString();
    }

    // One reference per Razorpay order. The order can be settled by the checkout call and by the webhook, the wallet changes only once.
    public static function razorpayReference(string $orderId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "verifyhub:razorpay:{$orderId}")->toString();
    }

    // $step = reserve | debit | release
    public static function verificationReference(int $verificationId, string $step): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "verifyhub:verification:{$verificationId}:{$step}")->toString();
    }

    // One reference per admin adjustment click. The same request_key sent twice changes the wallet only once.
    public static function adjustmentReference(int $retailerId, string $requestKey): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "verifyhub:adjust:{$retailerId}:{$requestKey}")->toString();
    }

    public function credit(int $retailerId, float|string $amount, string $reference, ?string $description = null): WalletLedger
    {
        return $this->apply('credit', $retailerId, $amount, $reference, $description);
    }

    public function reserve(int $retailerId, float|string $amount, string $reference, ?string $description = null): WalletLedger
    {
        return $this->apply('reserve', $retailerId, $amount, $reference, $description);
    }

    // Admin manual deduction. History is never edited, this is a new ledger row of type "reversal".
    public function reversal(int $retailerId, float|string $amount, string $reference, ?string $description = null): WalletLedger
    {
        return $this->apply('reversal', $retailerId, $amount, $reference, $description);
    }

    public function debit(int $retailerId, float|string $amount, string $reference, ?string $description = null): WalletLedger
    {
        return $this->apply('debit', $retailerId, $amount, $reference, $description);
    }

    public function release(int $retailerId, float|string $amount, string $reference, ?string $description = null): WalletLedger
    {
        return $this->apply('release', $retailerId, $amount, $reference, $description);
    }

    private function apply(string $type, int $retailerId, float|string $amount, string $reference, ?string $description): WalletLedger
    {
        $amount = round((float) $amount, 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($type, $retailerId, $amount, $reference, $description) {
            // Already processed: return the old entry, do not change the wallet again
            $existing = WalletLedger::where('reference', $reference)->first();
            if ($existing) {
                return $existing;
            }

            // Row lock so two requests cannot change the same wallet at the same moment
            $wallet = Wallet::where('retailer_id', $retailerId)->lockForUpdate()->firstOrFail();

            $available = round((float) $wallet->available_balance, 2);
            $reserved  = round((float) $wallet->reserved_balance, 2);
            $before    = $available; // balance before this change (used for the low balance alert)

            switch ($type) {
                case 'credit':
                    $available += $amount;
                    break;

                case 'reserve':
                    if ($available < $amount) {
                        throw new InsufficientBalanceException();
                    }
                    $available -= $amount;
                    $reserved  += $amount;
                    break;

                case 'reversal':
                    // Only the available money can be taken back (reserved money belongs to running checks)
                    if ($available < $amount) {
                        throw new InsufficientBalanceException('Retailer has only Rs ' . number_format($available, 2) . ' available to deduct.');
                    }
                    $available -= $amount;
                    break;

                case 'debit':
                case 'release':
                    if ($reserved < $amount) {
                        throw new \RuntimeException('Reserved balance is lower than the amount.');
                    }
                    $reserved -= $amount;
                    if ($type === 'release') {
                        $available += $amount;
                    }
                    break;
            }

            $available = round($available, 2);

            $wallet->update([
                'available_balance' => $available,
                'reserved_balance'  => round($reserved, 2),
                'version'           => $wallet->version + 1,
            ]);

            $entry = WalletLedger::create([
                'wallet_id'     => $wallet->id,
                'reference'     => $reference,
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $available,
                'description'   => $description,
            ]);

            // Only money going OUT can make the balance low
            if (in_array($type, ['reserve', 'reversal'], true)) {
                $this->checkLowBalance($retailerId, $before, $available);
            }

            return $entry;
        });
    }

    // Alert once, at the moment the available balance goes from "enough" to "below the line".
    // It runs inside the same transaction: if the wallet change is rolled back, the alert is rolled back too.
    private function checkLowBalance(int $retailerId, float $before, float $after): void
    {
        $threshold = (float) config('verification.low_balance_threshold', 500);

        if ($threshold <= 0 || $before < $threshold || $after >= $threshold) {
            return;
        }

        app(NotificationService::class)->lowBalance($retailerId, $after, $threshold);
    }
}