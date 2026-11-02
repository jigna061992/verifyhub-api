<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTopupRequest;
use App\Services\NotificationService;
use App\Services\WalletService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTopupController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }

    // List with status filter, search (retailer or UTR) and pagination
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $status  = $request->query('status');
        $search  = trim((string) $request->query('search', ''));

        $query = WalletTopupRequest::with(['retailer:id,business_name', 'approver:id,name'])
            ->latest()
            ->latest('id');

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('utr', 'like', "%{$search}%")
                    ->orWhereHas('retailer', fn ($r) => $r->where('business_name', 'like', "%{$search}%"));
            });
        }

        return $query->paginate($perPage)->through(fn ($t) => $this->row($t));
    }

    // Approve = mark approved + credit the wallet, both in one transaction
    public function approve(Request $request, $id)
    {
        $topup = DB::transaction(function () use ($request, $id) {
            // Lock the request row so a double click cannot approve it twice
            $topup = WalletTopupRequest::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($topup->status !== 'pending') {
                abort(422, "This request is already {$topup->status}.");
            }

            $topup->update([
                'status'      => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            $this->wallets->credit(
                $topup->retailer_id,
                $topup->amount,
                WalletService::topupReference($topup->id),
                "Wallet top-up #{$topup->id} ({$topup->method}, UTR {$topup->utr})"
            );

            return $topup;
        });

        app(NotificationService::class)->topupApproved($topup);

        Audit::log($request, 'topup.approved', 'wallet_topup_request', $topup->id, [
            'retailer_id' => $topup->retailer_id,
            'amount'      => (float) $topup->amount,
        ]);

        return response()->json($this->row($topup->fresh(['retailer:id,business_name', 'approver:id,name'])));
    }

    public function reject(Request $request, $id)
    {
        $topup = DB::transaction(function () use ($request, $id) {
            $topup = WalletTopupRequest::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($topup->status !== 'pending') {
                abort(422, "This request is already {$topup->status}.");
            }

            $topup->update([
                'status'      => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return $topup;
        });

        app(NotificationService::class)->topupRejected($topup);

        Audit::log($request, 'topup.rejected', 'wallet_topup_request', $topup->id, [
            'retailer_id' => $topup->retailer_id,
            'amount'      => (float) $topup->amount,
        ]);

        return response()->json($this->row($topup->fresh(['retailer:id,business_name', 'approver:id,name'])));
    }

    private function row(WalletTopupRequest $t): array
    {
        return [
            'id'            => $t->id,
            'business_name' => $t->retailer?->business_name,
            'amount'        => (float) $t->amount,
            'method'        => $t->method,
            'utr'           => $t->utr,
            'status'        => $t->status,
            'created_at'    => $t->created_at?->toIso8601String(),
            'decided_at'    => $t->approved_at?->toIso8601String(),
            'decided_by'    => $t->approver?->name,
        ];
    }
}