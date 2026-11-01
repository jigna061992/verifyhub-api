<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Retailer;
use App\Models\WalletLedger;
use App\Services\WalletService;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Http\Request;

// Super Admin: see every retailer wallet, add or deduct money by hand (always with a reason + audit), export statements
class AdminWalletController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $search  = trim((string) $request->query('search', ''));

        $query = Retailer::with(['wallet', 'user:id,name,mobile'])->orderBy('business_name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"));
            });
        }

        return $query->paginate($perPage)->through(fn ($r) => [
            'retailer_id'   => $r->id,
            'business_name' => $r->business_name,
            'owner'         => $r->user?->name,
            'status'        => $r->status,
            'available'     => (float) ($r->wallet?->available_balance ?? 0),
            'reserved'      => (float) ($r->wallet?->reserved_balance ?? 0),
        ]);
    }

    public function ledger(Request $request, int $id)
    {
        $retailer = Retailer::with('wallet')->findOrFail($id);
        $perPage  = min(max((int) $request->query('per_page', 10), 1), 100);

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return $this->ledgerQuery($request, $retailer)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn ($l) => [
                'id'            => $l->id,
                'type'          => $l->type,
                'amount'        => (float) $l->amount,
                'balance_after' => (float) $l->balance_after,
                'description'   => $l->description,
                'created_at'    => $l->created_at?->toIso8601String(),
            ]);
    }

    /**
     * POST /admin/wallets/{id}/adjust
     * { type: credit|debit, amount, reason, request_key (uuid made by the screen when the form opens) }
     */
    public function adjust(Request $request, int $id)
    {
        $retailer = Retailer::with('wallet')->findOrFail($id);

        $data = $request->validate([
            'type'        => 'required|in:credit,debit',
            'amount'      => 'required|numeric|decimal:0,2|min:1|max:100000',
            'reason'      => 'required|string|min:5|max:200',
            'request_key' => 'required|uuid',
        ], [
            'reason.min' => 'Please write a clear reason (at least 5 characters). It is saved in the audit log.',
        ]);

        abort_if(! $retailer->wallet, 422, 'This retailer has no wallet.');

        $reference = WalletService::adjustmentReference($retailer->id, $data['request_key']);

        // Double click / retry with the same key: answer again, but do not change the wallet or write a second audit row
        $already = WalletLedger::where('reference', $reference)->exists();

        $reason      = trim($data['reason']);
        $description = ($data['type'] === 'credit' ? 'Admin credit: ' : 'Admin deduction: ') . $reason;

        try {
            $entry = $data['type'] === 'credit'
                ? $this->wallets->credit($retailer->id, $data['amount'], $reference, $description)
                : $this->wallets->reversal($retailer->id, $data['amount'], $reference, $description);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $already) {
            Audit::log($request, 'wallet.adjusted', 'wallet', $entry->wallet_id, [
                'retailer_id'   => $retailer->id,
                'type'          => $data['type'],
                'amount'        => (float) $data['amount'],
                'reason'        => $reason,
                'balance_after' => (float) $entry->balance_after,
            ]);
        }

        return response()->json([
            'message'       => $data['type'] === 'credit' ? 'Money added to the wallet.' : 'Money deducted from the wallet.',
            'balance_after' => (float) $entry->balance_after,
        ], $already ? 200 : 201);
    }

    // CSV statement of one retailer wallet (optional ?from=YYYY-MM-DD&to=YYYY-MM-DD)
    public function export(Request $request, int $id)
    {
        $retailer = Retailer::with('wallet')->findOrFail($id);

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        $query = $this->ledgerQuery($request, $retailer)->orderBy('id')->limit(50000);

        $rows = (function () use ($query) {
            foreach ($query->cursor() as $l) {
                yield [
                    $l->created_at?->format('Y-m-d H:i:s'),
                    $l->type,
                    $l->description ?? '',
                    number_format((float) $l->amount, 2, '.', ''),
                    number_format((float) $l->balance_after, 2, '.', ''),
                    $l->reference,
                ];
            }
        })();

        Audit::log($request, 'wallet.statement.exported', 'retailer', $retailer->id, []);

        return Csv::download(
            'wallet-statement-retailer-' . $retailer->id . '-' . now()->format('Ymd') . '.csv',
            ['Date', 'Type', 'Description', 'Amount', 'Balance after', 'Transaction ID'],
            $rows
        );
    }

    private function ledgerQuery(Request $request, Retailer $retailer)
    {
        $query = WalletLedger::where('wallet_id', $retailer->wallet?->id ?? 0);

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        return $query;
    }
}
