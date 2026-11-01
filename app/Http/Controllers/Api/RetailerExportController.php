<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Verification;
use App\Models\WalletLedger;
use App\Support\Csv;
use Illuminate\Http\Request;

// Retailer CSV downloads. Always filtered by the logged-in retailer, nobody can pass another retailer id.
class RetailerExportController extends Controller
{
    // GET /retailer/export/wallet-ledger?from=&to=
    public function ledger(Request $request)
    {
        $retailer = $this->retailer($request);
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        $query = WalletLedger::where('wallet_id', $retailer->wallet?->id ?? 0);

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        $query->orderBy('id')->limit(50000);

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

        return Csv::download(
            'wallet-statement-' . now()->format('Ymd') . '.csv',
            ['Date', 'Type', 'Description', 'Amount', 'Balance after', 'Transaction ID'],
            $rows
        );
    }

    // GET /retailer/export/verifications?status=&service=&from=&to=
    public function verifications(Request $request)
    {
        $retailer = $this->retailer($request);
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        $status = $request->query('status');
        $code   = $request->query('service');
        $bundle = strtoupper((string) config('kyc.service_code', 'COMPLETE_KYC'));

        // Same rules as the History screen: "Complete KYC" is its own service, every other code is a single check
        $wantChecks = ! $code || strtoupper($code) !== $bundle;
        $wantKyc    = ! $code || strtoupper($code) === $bundle;

        $checks = collect();
        if ($wantChecks) {
            $query = Verification::with('service:id,code,name')->where('retailer_id', $retailer->id);

            if (in_array($status, ['pending', 'success', 'failed'], true)) {
                $query->where('status', $status);
            }
            if ($code) {
                $query->whereHas('service', fn ($s) => $s->where('code', $code));
            }
            if ($from = $request->query('from')) {
                $query->where('created_at', '>=', $from . ' 00:00:00');
            }
            if ($to = $request->query('to')) {
                $query->where('created_at', '<=', $to . ' 23:59:59');
            }

            // Only masked identifiers and the outcome. The stored result (encrypted) is never exported.
            $checks = $query->orderBy('id')->limit(50000)->get()->map(fn ($v) => [
                $v->created_at,
                $v->reference,
                $v->service?->name ?? '',
                $v->input_masked,
                $v->status,
                $v->status === 'success' ? number_format((float) $v->price, 2, '.', '') : '0.00',
                $v->failure_reason ?? '',
            ]);
        }

        // Complete KYC cases: only the last 4 digits of the mobile, never the name or the step results
        $cases = collect();
        if ($wantKyc) {
            $statusMap = ['pending' => ['in_progress'], 'success' => ['completed'], 'failed' => ['failed', 'cancelled', 'expired']];

            $query = KycCase::where('retailer_id', $retailer->id);

            if (isset($statusMap[$status])) {
                $query->whereIn('status', $statusMap[$status]);
            }
            if ($from = $request->query('from')) {
                $query->where('created_at', '>=', $from . ' 00:00:00');
            }
            if ($to = $request->query('to')) {
                $query->where('created_at', '<=', $to . ' 23:59:59');
            }

            $cases = $query->orderBy('id')->limit(50000)->get()->map(fn ($c) => [
                $c->created_at,
                $c->reference,
                'Complete KYC',
                'XXXXXX' . $c->customer_mobile_last4,
                $c->status,
                $c->status === 'completed' ? number_format((float) $c->price, 2, '.', '') : '0.00',
                $c->failure_reason ?? '',
            ]);
        }

        // Oldest first, like before. The date column is formatted only after sorting.
        $rows = (function () use ($checks, $cases) {
            foreach ($checks->concat($cases)->sortBy(fn ($r) => $r[0]?->timestamp ?? 0)->values() as $r) {
                $r[0] = $r[0]?->format('Y-m-d H:i:s');
                yield $r;
            }
        })();

        return Csv::download(
            'verification-history-' . now()->format('Ymd') . '.csv',
            ['Date', 'Reference', 'Service', 'Identifier (masked)', 'Status', 'Charged', 'Note'],
            $rows
        );
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer->loadMissing('wallet');
    }
}
