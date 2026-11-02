<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\Reconciliation;
use App\Models\Retailer;
use App\Models\WalletTopupRequest;

/**
 * The ONLY place that creates notifications.
 * A notification must never break money work, so every failure is reported and swallowed.
 */
class NotificationService
{
    public function notify(?int $userId, string $type, string $title, string $message, array $data = []): ?NotificationLog
    {
        if (! $userId) {
            return null;
        }

        try {
            return NotificationLog::create([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'message' => mb_substr($message, 0, 500),
                'data'    => $data ?: null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function topupApproved(WalletTopupRequest $topup): void
    {
        $this->notify(
            $this->userIdOf($topup->retailer_id),
            'topup_approved',
            'Top-up approved',
            "Your top-up of {$this->rs($topup->amount)} (UTR {$topup->utr}) was approved and added to your wallet.",
            ['link' => '/retailer/wallet', 'topup_id' => $topup->id, 'amount' => (float) $topup->amount]
        );
    }

    public function topupRejected(WalletTopupRequest $topup): void
    {
        $this->notify(
            $this->userIdOf($topup->retailer_id),
            'topup_rejected',
            'Top-up rejected',
            "Your top-up request of {$this->rs($topup->amount)} (UTR {$topup->utr}) was rejected. Please check the UTR and amount, or contact the administrator.",
            ['link' => '/retailer/wallet', 'topup_id' => $topup->id, 'amount' => (float) $topup->amount]
        );
    }

    // Money paid online (Razorpay) and credited to the wallet
    public function walletCreditedOnline(int $retailerId, float $amount): void
    {
        $this->notify(
            $this->userIdOf($retailerId),
            'wallet_credited',
            'Payment received',
            "{$this->rs($amount)} was added to your wallet through online payment.",
            ['link' => '/retailer/wallet', 'amount' => $amount]
        );
    }

    // Admin added or deducted money by hand. The admin's reason stays in the audit log, it is not shown here.
    public function walletAdjusted(int $retailerId, string $type, float $amount): void
    {
        $credit = $type === 'credit';

        $this->notify(
            $this->userIdOf($retailerId),
            $credit ? 'wallet_credited' : 'wallet_debited',
            $credit ? 'Wallet credited by admin' : 'Wallet debited by admin',
            $credit
                ? "{$this->rs($amount)} was added to your wallet by the administrator."
                : "{$this->rs($amount)} was deducted from your wallet by the administrator.",
            ['link' => '/retailer/wallet', 'amount' => $amount]
        );
    }

    // An admin resolved a check that was under review (the provider did not answer in time)
    public function verificationResolved(Reconciliation $rec): void
    {
        $service = $rec->verification?->service?->name ?? 'Verification';
        $no      = ReconciliationService::requestNo((int) $rec->verification_id);
        $charged = $rec->outcome === 'success';

        $this->notify(
            $this->userIdOf((int) $rec->retailer_id),
            'verification_resolved',
            $charged ? 'Verification completed' : 'Verification not completed',
            $charged
                ? "{$service} {$no} was confirmed by the provider. {$this->rs($rec->amount)} was charged and the result is in your history."
                : "{$service} {$no} could not be completed by the provider. {$this->rs($rec->amount)} was returned to your wallet.",
            ['link' => '/retailer/history', 'verification_id' => (int) $rec->verification_id, 'amount' => (float) $rec->amount]
        );
    }

    public function lowBalance(int $retailerId, float $available, float $threshold): void
    {
        $this->notify(
            $this->userIdOf($retailerId),
            'low_balance',
            'Low wallet balance',
            "Your available balance is {$this->rs($available)}, below {$this->rs($threshold)}. Add money to keep verifying customers.",
            ['link' => '/retailer/wallet', 'available' => $available, 'threshold' => $threshold]
        );
    }

    private function userIdOf(int $retailerId): ?int
    {
        return Retailer::whereKey($retailerId)->value('user_id');
    }

    private function rs(float|string $amount): string
    {
        return 'Rs ' . number_format((float) $amount, 2);
    }
}
