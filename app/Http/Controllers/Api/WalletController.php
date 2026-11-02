<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletLedger;
use App\Models\WalletTopupRequest;
use Illuminate\Http\Request;

// Retailer side of the wallet. A retailer can only ever see and create their OWN data.
class WalletController extends Controller
{
    private const MAX_PENDING = 5;

    public function summary(Request $request)
    {
        $retailer = $this->retailer($request);
        $wallet   = $retailer->wallet;

        return response()->json([
            'available'     => (float) ($wallet?->available_balance ?? 0),
            'reserved'      => (float) ($wallet?->reserved_balance ?? 0),
            'pending_count' => WalletTopupRequest::where('retailer_id', $retailer->id)->where('status', 'pending')->count(),
        ]);
    }

    public function ledger(Request $request)
    {
        $wallet  = $this->retailer($request)->wallet;
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);

        return WalletLedger::where('wallet_id', $wallet?->id)
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

    public function topups(Request $request)
    {
        $retailer = $this->retailer($request);
        $perPage  = min(max((int) $request->query('per_page', 10), 1), 100);

        return WalletTopupRequest::where('retailer_id', $retailer->id)
            ->latest()
            ->latest('id')
            ->paginate($perPage)
            ->through(fn ($t) => [
                'id'         => $t->id,
                'amount'     => (float) $t->amount,
                'method'     => $t->method,
                'utr'        => $t->utr,
                'status'     => $t->status,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);
    }

    // Retailer pays the admin outside the app (UPI/NEFT/IMPS) and submits the UTR here
    public function storeTopup(Request $request)
    {
        $retailer = $this->retailer($request);

        $request->merge(['utr' => strtoupper(trim((string) $request->input('utr')))]);

        $data = $request->validate([
            'amount' => 'required|numeric|decimal:0,2|min:100|max:100000',
            'method' => 'required|in:UPI,NEFT,IMPS',
            'utr'    => ['required', 'regex:/^[A-Z0-9]{6,30}$/', 'unique:wallet_topup_requests,utr'],
        ], [
            'amount.min' => 'Minimum top-up amount is Rs 100.',
            'amount.max' => 'Maximum top-up amount per request is Rs 1,00,000.',
            'utr.regex'  => 'UTR must be 6 to 30 letters or digits.',
            'utr.unique' => 'This UTR has already been submitted.',
        ]);

        $pending = WalletTopupRequest::where('retailer_id', $retailer->id)->where('status', 'pending')->count();
        if ($pending >= self::MAX_PENDING) {
            return response()->json([
                'message' => 'You already have ' . self::MAX_PENDING . ' requests waiting for approval. Please wait for them to be processed.',
            ], 422);
        }

        $topup = WalletTopupRequest::create([
            'retailer_id' => $retailer->id,
            'amount'      => $data['amount'],
            'method'      => $data['method'],
            'utr'         => $data['utr'],
        ]);
        $topup->refresh(); // load the database defaults (status = pending), otherwise "status" is null in the response

        return response()->json([
            'id'         => $topup->id,
            'amount'     => (float) $topup->amount,
            'method'     => $topup->method,
            'utr'        => $topup->utr,
            'status'     => $topup->status,
            'created_at' => $topup->created_at->toIso8601String(),
        ], 201);
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer->loadMissing('wallet');
    }
}
