<?php

namespace App\Services;

use App\Models\Reconciliation;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checks whose provider answer is unknown (the provider call timed out or crashed).
 *
 *  hold()    : the check stays "pending" (stage = reconciliation) and the money stays on hold in the wallet.
 *  resolve() : an admin looks at the provider's own dashboard and decides
 *                success -> the provider did process it: the result is saved and the held money is charged
 *                failed  -> the provider did not process it: the held money goes back to the wallet
 *
 * Wallet changes use the same references as a normal check (verificationReference), so resolving twice
 * (double click, retry) can never charge or release the money twice.
 */
class ReconciliationService
{
    public const STAGE = 'reconciliation';

    // Shown to the retailer while the check is under review
    public const RETAILER_MESSAGE = 'The provider did not confirm the result yet. Your amount is on hold and this request is under review. You will be notified when it is resolved.';

    // Fields the admin types for each service (copied from the provider's dashboard) and the label shown in history
    public const RESULT_FIELDS = [
        'PAN'  => ['name' => 'name', 'status' => 'pan_status', 'status_default' => 'VALID'],
        'BANK' => ['name' => 'account_holder', 'status' => 'account_status', 'status_default' => 'ACTIVE'],
        'GST'  => ['name' => 'legal_name', 'status' => 'gst_status', 'status_default' => 'ACTIVE'],
    ];

    public function __construct(private WalletService $wallets, private NotificationService $notifications)
    {
    }

    // Readable request number, same format as the retailer screens (VRF-000123)
    public static function requestNo(int $verificationId): string
    {
        return 'VRF-' . str_pad((string) $verificationId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Keeps the check open and the money on hold, and queues it for the admin.
     * Called by VerificationRunner when the provider call fails with an exception.
     */
    public function hold(Verification $verification, string $reason): Verification
    {
        return DB::transaction(function () use ($verification, $reason) {
            $v = Verification::whereKey($verification->id)->lockForUpdate()->first();

            if (! $v || $v->status !== 'pending') {
                return $v ?? $verification;
            }

            $v->update([
                'stage'          => self::STAGE,
                'failure_reason' => self::RETAILER_MESSAGE,
            ]);

            Reconciliation::firstOrCreate(
                ['verification_id' => $v->id],
                [
                    'retailer_id' => $v->retailer_id,
                    'service_id'  => $v->service_id,
                    'amount'      => $v->price,
                    'status'      => 'open',
                    'reason'      => mb_substr($reason, 0, 255),
                ]
            );

            return $v->fresh();
        });
    }

    /**
     * @param string      $outcome success | failed
     * @param array       $result  provider answer typed by the admin: name, status (only for outcome = success)
     * @return Reconciliation the resolved row
     */
    public function resolve(int $reconciliationId, string $outcome, array $result, string $note, User $admin): Reconciliation
    {
        $resolved = DB::transaction(function () use ($reconciliationId, $outcome, $result, $note, $admin) {
            $rec = Reconciliation::whereKey($reconciliationId)->lockForUpdate()->firstOrFail();

            if ($rec->status !== 'open') {
                throw ValidationException::withMessages(['outcome' => 'This request is already resolved.']);
            }

            $v = Verification::with('service:id,code,name')->whereKey($rec->verification_id)->lockForUpdate()->firstOrFail();

            // The check must still be the one we are holding. Anything else means the data changed under us.
            if ($v->status !== 'pending' || $v->stage !== self::STAGE) {
                throw ValidationException::withMessages(['outcome' => 'This check is no longer waiting for reconciliation.']);
            }

            $price = round((float) $v->price, 2);

            if ($outcome === 'success') {
                if ($price > 0) {
                    $this->wallets->debit(
                        $v->retailer_id,
                        $price,
                        WalletService::verificationReference($v->id, 'debit'),
                        "{$v->service->name} charge (reconciled)"
                    );
                }

                $v->update([
                    'status'         => 'success',
                    'stage'          => null,
                    'failure_reason' => null,
                    'result'         => $this->buildResult($v, $result),
                ]);
            } else {
                if ($price > 0) {
                    $this->wallets->release(
                        $v->retailer_id,
                        $price,
                        WalletService::verificationReference($v->id, 'release'),
                        "{$v->service->name} not completed, amount returned (reconciled)"
                    );
                }

                $v->update([
                    'status'         => 'failed',
                    'stage'          => null,
                    'failure_reason' => 'The provider could not complete this check. The amount was returned to your wallet.',
                ]);
            }

            $rec->update([
                'status'      => 'resolved',
                'outcome'     => $outcome,
                'note'        => mb_substr($note, 0, 255),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            return $rec->fresh(['verification.service:id,code,name', 'retailer:id,business_name']);
        });

        // After the commit. A notification must never undo money work.
        $this->notifications->verificationResolved($resolved);

        return $resolved;
    }

    // Provider answer typed by the admin -> the same fields a normal successful check stores
    private function buildResult(Verification $v, array $input): array
    {
        $map  = self::RESULT_FIELDS[$v->service->code];
        $name = trim((string) ($input['name'] ?? ''));

        $data = [
            $map['name']   => $name,
            $map['status'] => strtoupper(trim((string) ($input['status'] ?? ''))) ?: $map['status_default'],
        ];

        // Name match, the same rule as a normal check (only when the retailer typed the customer name)
        $given = trim((string) (($v->meta ?? [])['customer_name'] ?? ''));
        if ($given !== '' && $name !== '' && in_array($v->service->code, ['PAN', 'BANK', 'GST'], true)) {
            $score = NameMatcher::score($given, $name);

            $data['name_match_score'] = $score;
            $data['name_match']       = $score >= (int) config('kyc.name_match.' . strtolower($v->service->code), 80) ? 'match' : 'mismatch';
        }

        return $data;
    }
}
