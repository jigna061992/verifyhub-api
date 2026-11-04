<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Retailer;
use App\Models\WalletLedger;
use App\Services\NotificationService;
use App\Services\WalletService;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Super Admin: see every retailer wallet, add or deduct money by hand (always with a reason + audit), export statements
class AdminWalletController extends Controller
{
    // How the money reached us (shown in the "Payment mode" list of the adjustment form)
    public const PAYMENT_MODES = ['NEFT', 'IMPS', 'RTGS', 'UPI', 'CASH', 'CHEQUE', 'OTHER'];

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
            'code'          => 'RT' . (1000 + $r->id),
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
                'payment_mode'  => $l->payment_mode,
                'txn_reference' => $l->txn_reference,
                'created_at'    => $l->created_at?->toIso8601String(),
            ]);
    }

    /**
     * POST /admin/wallets/{id}/adjust
     * { type: credit|debit, amount, payment_mode, txn_reference, remarks?, request_key (uuid made by the screen when the form opens) }
     * "reason" is still accepted as the old name of "remarks".
     */
    public function adjust(Request $request, int $id)
    {
        $retailer = Retailer::with('wallet')->findOrFail($id);

        $data = $request->validate([
            'type'          => 'required|in:credit,debit',
            'amount'        => 'required|numeric|decimal:0,2|min:1|max:100000',
            'payment_mode'  => ['required', Rule::in(self::PAYMENT_MODES)],
            'txn_reference' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9\-_\/]+$/'],
            'remarks'       => 'nullable|string|max:200',
            'reason'        => 'nullable|string|max:200',
            'request_key'   => 'required|uuid',
        ], [
            'txn_reference.regex' => 'Use only letters, numbers, - _ and / in the reference.',
            'txn_reference.min'   => 'The transaction reference must be at least 6 characters.',
        ]);

        abort_if(! $retailer->wallet, 422, 'This retailer has no wallet.');

        $reference = WalletService::adjustmentReference($retailer->id, $data['request_key']);
        $txnRef    = strtoupper(trim($data['txn_reference']));
        $remarks   = trim((string) ($data['remarks'] ?? $data['reason'] ?? ''));

        // Double click / retry with the same key: answer again, but do not change the wallet or write a second audit row
        $already = WalletLedger::where('reference', $reference)->exists();

        // The same bank reference (UTR) can be used for one entry only
        if (! $already && WalletLedger::where('txn_reference', $txnRef)->exists()) {
            throw ValidationException::withMessages(['txn_reference' => 'This transaction reference is already used for another entry.']);
        }

        $description = ($data['type'] === 'credit' ? 'Admin credit' : 'Admin deduction')
            . " ({$data['payment_mode']}, ref {$txnRef})"
            . ($remarks !== '' ? ": {$remarks}" : '');

        try {
            $entry = DB::transaction(function () use ($data, $retailer, $reference, $description, $txnRef, $already) {
                $entry = $data['type'] === 'credit'
                    ? $this->wallets->credit($retailer->id, $data['amount'], $reference, $description)
                    : $this->wallets->reversal($retailer->id, $data['amount'], $reference, $description);

                // Saved in the same transaction as the wallet change, so one can never exist without the other
                if (! $already) {
                    $entry->update(['payment_mode' => $data['payment_mode'], 'txn_reference' => $txnRef]);
                }

                return $entry;
            });
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $already) {
            app(NotificationService::class)->walletAdjusted($retailer->id, $data['type'], (float) $data['amount']);

            Audit::log($request, 'wallet.adjusted', 'wallet', $entry->wallet_id, [
                'retailer_id'   => $retailer->id,
                'type'          => $data['type'],
                'amount'        => (float) $data['amount'],
                'payment_mode'  => $data['payment_mode'],
                'txn_reference' => $txnRef,
                'remarks'       => $remarks,
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
                    $l->payment_mode ?? '',
                    $l->txn_reference ?? '',
                    number_format((float) $l->amount, 2, '.', ''),
                    number_format((float) $l->balance_after, 2, '.', ''),
                    $l->reference,
                ];
            }
        })();

        Audit::log($request, 'wallet.statement.exported', 'retailer', $retailer->id, []);

        return Csv::download(
            'wallet-statement-retailer-' . $retailer->id . '-' . now()->format('Ymd') . '.csv',
            ['Date', 'Type', 'Description', 'Payment mode', 'Bank reference', 'Amount', 'Balance after', 'Transaction ID'],
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
