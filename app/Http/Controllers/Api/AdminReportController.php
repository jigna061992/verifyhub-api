<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Reconciliation;
use App\Models\Retailer;
use App\Models\RetailerPricing;
use App\Models\Verification;
use App\Models\VerificationService;
use App\Models\WalletLedger;
use App\Support\Csv;
use Carbon\Carbon;
use Generator;
use Illuminate\Http\Request;

// Super admin: revenue, cost, profit (margin) for a date range.
// Revenue is counted only for successful single checks and completed Complete KYC cases
// (failed / cancelled / expired cases return the money to the retailer, so they earn nothing).
// Margin of one check = the price charged to the retailer - the provider cost. Both are copied into the
// verification at the time of the check, so a later price change never rewrites old numbers.
class AdminReportController extends Controller
{
    private const MAX_DAYS = 366;

    public function summary(Request $request)
    {
        [$from, $to, $error] = $this->range($request);

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json($this->build($from, $to));
    }

    // CSV with one row per retailer
    public function export(Request $request)
    {
        [$from, $to, $error] = $this->range($request);

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $data     = $this->build($from, $to);
        $filename = 'verifyhub-report-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Retailer', 'Checks', 'Successful checks', 'KYC cases', 'Completed KYC', 'Revenue', 'Provider cost', 'Profit']);

            foreach ($data['by_retailer'] as $r) {
                fputcsv($out, [
                    $this->safeCell($r['business_name']),
                    $r['checks'], $r['checks_success'], $r['kyc'], $r['kyc_completed'],
                    number_format($r['revenue'], 2, '.', ''),
                    number_format($r['cost'], 2, '.', ''),
                    number_format($r['profit'], 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // CSV with one row per check / KYC case: price, provider cost and margin of every transaction
    public function transactions(Request $request)
    {
        [$from, $to, $error] = $this->range($request);

        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $filename = 'verifyhub-transactions-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv';

        return Csv::download(
            $filename,
            ['Date', 'Request', 'Type', 'Retailer', 'Service', 'Status', 'Charged (INR)', 'Provider cost (INR)', 'Margin (INR)'],
            $this->transactionRows($from, $to)
        );
    }

    /* ------------------------------------------------------------------ */

    // One row at a time (lazyById reads 500 rows per query), so a big range never fills the memory.
    // Only charged rows carry money: a failed or cancelled check shows 0.00 (the wallet was released).
    private function transactionRows(Carbon $from, Carbon $to): Generator
    {
        $revenue = 0.0;
        $cost    = 0.0;

        $checks = Verification::with(['retailer:id,business_name', 'service:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->lazyById(500);

        foreach ($checks as $v) {
            $charged = $v->status === 'success';
            $price   = $charged ? (float) $v->price : 0.0;
            $pCost   = $charged ? (float) $v->provider_cost : 0.0;
            $revenue += $price;
            $cost    += $pCost;

            yield [
                $v->created_at?->format('Y-m-d H:i'),
                'VRF-' . str_pad((string) $v->id, 6, '0', STR_PAD_LEFT),
                'Single check',
                $v->retailer?->business_name ?? 'Deleted retailer',
                $v->service?->name ?? 'Unknown',
                $v->status,
                $this->money($price),
                $this->money($pCost),
                $this->money($price - $pCost),
            ];
        }

        $cases = KycCase::with(['retailer:id,business_name', 'service:id,name'])
            ->whereBetween('created_at', [$from, $to])
            ->lazyById(500);

        foreach ($cases as $c) {
            $charged = $c->status === 'completed';
            $price   = $charged ? (float) $c->price : 0.0;
            $pCost   = $charged ? (float) $c->provider_cost : 0.0;
            $revenue += $price;
            $cost    += $pCost;

            yield [
                $c->created_at?->format('Y-m-d H:i'),
                'KYC-' . str_pad((string) $c->id, 6, '0', STR_PAD_LEFT),
                'Complete KYC',
                $c->retailer?->business_name ?? 'Deleted retailer',
                $c->service?->name ?? 'Unknown',
                $c->status,
                $this->money($price),
                $this->money($pCost),
                $this->money($price - $pCost),
            ];
        }

        yield ['', '', 'TOTAL', '', '', '', $this->money($revenue), $this->money($cost), $this->money($revenue - $cost)];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    // Margin in % of revenue (null when there is no revenue, so the screen can show a dash)
    private function marginPercent(float $revenue, float $cost): ?float
    {
        return $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null;
    }

    // Prices that are lower than the provider cost: every successful check at that price loses money
    private function belowCostAlerts(): array
    {
        $alerts = [];

        foreach (VerificationService::where('enabled', true)->get() as $s) {
            if ((float) $s->default_price < (float) $s->provider_cost) {
                $alerts[] = [
                    'scope'   => 'Default price',
                    'service' => $s->name,
                    'price'   => (float) $s->default_price,
                    'cost'    => (float) $s->provider_cost,
                ];
            }
        }

        $custom = RetailerPricing::query()
            ->join('verification_services as s', 's.id', '=', 'retailer_pricings.service_id')
            ->join('retailers as r', 'r.id', '=', 'retailer_pricings.retailer_id')
            ->where('s.enabled', true)
            ->whereColumn('retailer_pricings.custom_price', '<', 's.provider_cost')
            ->select('r.business_name', 's.name as service', 'retailer_pricings.custom_price as price', 's.provider_cost as cost')
            ->limit(50)
            ->get();

        foreach ($custom as $row) {
            $alerts[] = [
                'scope'   => $row->business_name,
                'service' => $row->service,
                'price'   => (float) $row->price,
                'cost'    => (float) $row->cost,
            ];
        }

        return $alerts;
    }

    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->subDays(29)->startOfDay();
        $to   = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : now()->endOfDay();

        if ($to->lt($from)) {
            return [null, null, 'The end date must be after the start date.'];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            return [null, null, 'Please choose a range of 1 year or less.'];
        }

        return [$from, $to, null];
    }

    private function build(Carbon $from, Carbon $to): array
    {
        $ok = "CASE WHEN status = 'success' THEN %s ELSE 0 END";
        $kc = "CASE WHEN status = 'completed' THEN %s ELSE 0 END";

        // ---- Single checks (PAN, Bank, GST...) ----
        $vService = Verification::whereBetween('created_at', [$from, $to])
            ->selectRaw('service_id, COUNT(*) AS total, SUM(' . sprintf($ok, '1') . ') AS ok_count, SUM(' . sprintf($ok, 'price') . ') AS revenue, SUM(' . sprintf($ok, 'provider_cost') . ') AS cost')
            ->groupBy('service_id')->get();

        $vRetailer = Verification::whereBetween('created_at', [$from, $to])
            ->selectRaw('retailer_id, COUNT(*) AS total, SUM(' . sprintf($ok, '1') . ') AS ok_count, SUM(' . sprintf($ok, 'price') . ') AS revenue, SUM(' . sprintf($ok, 'provider_cost') . ') AS cost')
            ->groupBy('retailer_id')->get()->keyBy('retailer_id');

        $vDaily = Verification::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) AS d, SUM(' . sprintf($ok, 'price') . ') AS revenue, SUM(' . sprintf($ok, 'provider_cost') . ') AS cost')
            ->groupBy('d')->get()->keyBy('d');

        // ---- Complete KYC cases ----
        $kService = KycCase::whereBetween('created_at', [$from, $to])
            ->selectRaw('service_id, COUNT(*) AS total, SUM(' . sprintf($kc, '1') . ') AS ok_count, SUM(' . sprintf($kc, 'price') . ') AS revenue, SUM(' . sprintf($kc, 'provider_cost') . ') AS cost')
            ->groupBy('service_id')->get();

        $kRetailer = KycCase::whereBetween('created_at', [$from, $to])
            ->selectRaw('retailer_id, COUNT(*) AS total, SUM(' . sprintf($kc, '1') . ') AS ok_count, SUM(' . sprintf($kc, 'price') . ') AS revenue, SUM(' . sprintf($kc, 'provider_cost') . ') AS cost')
            ->groupBy('retailer_id')->get()->keyBy('retailer_id');

        $kDaily = KycCase::whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) AS d, SUM(' . sprintf($kc, 'price') . ') AS revenue, SUM(' . sprintf($kc, 'provider_cost') . ') AS cost')
            ->groupBy('d')->get()->keyBy('d');

        // ---- Counts per day and outcome: single checks and Complete KYC cases together ----
        // verified = success / completed, pending = pending / in_progress, failed = everything else that ended
        $outcome = ['success' => 'verified', 'completed' => 'verified', 'pending' => 'pending', 'in_progress' => 'pending'];

        $perDay = [];
        foreach ([Verification::class, KycCase::class] as $model) {
            $model::whereBetween('created_at', [$from, $to])
                ->selectRaw('DATE(created_at) AS d, status, COUNT(*) AS c')
                ->groupBy('d', 'status')->get()
                ->each(function ($row) use (&$perDay, $outcome) {
                    $bucket = $outcome[$row->status] ?? 'failed';
                    $perDay[$row->d][$bucket] = ($perDay[$row->d][$bucket] ?? 0) + (int) $row->c;
                });
        }

        // ---- By service (single checks and the KYC bundle share the same table of services) ----
        $names = VerificationService::pluck('name', 'id');

        $byService = [];
        foreach ([$vService, $kService] as $set) {
            foreach ($set as $row) {
                $id = $row->service_id;
                $byService[$id] ??= ['service' => $names[$id] ?? 'Unknown', 'total' => 0, 'success' => 0, 'revenue' => 0.0, 'cost' => 0.0];
                $byService[$id]['total']   += (int) $row->total;
                $byService[$id]['success'] += (int) $row->ok_count;
                $byService[$id]['revenue'] += (float) $row->revenue;
                $byService[$id]['cost']    += (float) $row->cost;
            }
        }

        $byService = collect($byService)->map(function ($s) {
            $s['profit']       = round($s['revenue'] - $s['cost'], 2);
            $s['revenue']      = round($s['revenue'], 2);
            $s['cost']         = round($s['cost'], 2);
            $s['success_rate']   = $s['total'] > 0 ? round($s['success'] / $s['total'] * 100, 1) : null;
            $s['margin_percent'] = $this->marginPercent($s['revenue'], $s['cost']);

            return $s;
        })->sortByDesc('revenue')->values();

        // ---- By retailer ----
        $retailerIds = $vRetailer->keys()->merge($kRetailer->keys())->unique();
        $retailers   = Retailer::whereIn('id', $retailerIds)->pluck('business_name', 'id');

        $byRetailer = $retailerIds->map(function ($id) use ($vRetailer, $kRetailer, $retailers) {
            $v = $vRetailer[$id] ?? null;
            $k = $kRetailer[$id] ?? null;

            $revenue = (float) ($v->revenue ?? 0) + (float) ($k->revenue ?? 0);
            $cost    = (float) ($v->cost ?? 0) + (float) ($k->cost ?? 0);

            return [
                'retailer_id'    => $id,
                'business_name'  => $retailers[$id] ?? 'Deleted retailer',
                'checks'         => (int) ($v->total ?? 0),
                'checks_success' => (int) ($v->ok_count ?? 0),
                'kyc'            => (int) ($k->total ?? 0),
                'kyc_completed'  => (int) ($k->ok_count ?? 0),
                'revenue'        => round($revenue, 2),
                'cost'           => round($cost, 2),
                'profit'         => round($revenue - $cost, 2),
                'margin_percent' => $this->marginPercent($revenue, $cost),
            ];
        })->sortByDesc('revenue')->values();

        // ---- Daily series (every day of the range, also days with no activity) ----
        $daily = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();

            $revenue = (float) ($vDaily[$key]->revenue ?? 0) + (float) ($kDaily[$key]->revenue ?? 0);
            $cost    = (float) ($vDaily[$key]->cost ?? 0) + (float) ($kDaily[$key]->cost ?? 0);

            $verified = (int) ($perDay[$key]['verified'] ?? 0);
            $failed   = (int) ($perDay[$key]['failed'] ?? 0);
            $pending  = (int) ($perDay[$key]['pending'] ?? 0);

            $daily[] = [
                'date' => $key, 'revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2),
                'total' => $verified + $failed + $pending, 'verified' => $verified, 'failed' => $failed, 'pending' => $pending,
            ];
        }

        // ---- Monthly series (the daily numbers added up per month) ----
        $months = [];
        foreach ($daily as $d) {
            $m = substr($d['date'], 0, 7);
            $months[$m] ??= ['month' => $m, 'revenue' => 0.0, 'cost' => 0.0, 'verified' => 0, 'failed' => 0, 'pending' => 0];
            $months[$m]['revenue']  += $d['revenue'];
            $months[$m]['cost']     += $d['cost'];
            $months[$m]['verified'] += $d['verified'];
            $months[$m]['failed']   += $d['failed'];
            $months[$m]['pending']  += $d['pending'];
        }

        $monthly = collect($months)->map(fn ($m) => [
            'month'          => $m['month'],
            'revenue'        => round($m['revenue'], 2),
            'cost'           => round($m['cost'], 2),
            'profit'         => round($m['revenue'] - $m['cost'], 2),
            'margin_percent' => $this->marginPercent($m['revenue'], $m['cost']),
            'total'          => $m['verified'] + $m['failed'] + $m['pending'],
            'verified'       => $m['verified'],
            'failed'         => $m['failed'],
            'pending'        => $m['pending'],
        ])->values();

        // ---- Failed / still running counts, and money on hold right now (open reconciliations) ----
        $vStatus = Verification::whereBetween('created_at', [$from, $to])->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $kStatus = KycCase::whereBetween('created_at', [$from, $to])->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');
        $hold    = Reconciliation::where('status', 'open')->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS a')->first();

        // ---- Wallet movements per day (money added, money charged for checks, admin deductions) ----
        $walletMap = [];
        WalletLedger::whereBetween('created_at', [$from, $to])
            ->whereIn('type', ['credit', 'debit', 'reversal'])
            ->selectRaw('DATE(created_at) AS d, type, COUNT(*) AS c, SUM(amount) AS a')
            ->groupBy('d', 'type')->get()
            ->each(function ($row) use (&$walletMap) {
                $walletMap[$row->d][$row->type] = ['c' => (int) $row->c, 'a' => (float) $row->a];
            });

        $walletDaily = [];
        $walletTotals = ['credited' => 0.0, 'charged' => 0.0, 'deducted' => 0.0, 'transactions' => 0];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $r   = $walletMap[$key] ?? [];

            $row = [
                'date'         => $key,
                'credited'     => round($r['credit']['a'] ?? 0, 2),
                'charged'      => round($r['debit']['a'] ?? 0, 2),
                'deducted'     => round($r['reversal']['a'] ?? 0, 2),
                'transactions' => (int) (($r['credit']['c'] ?? 0) + ($r['debit']['c'] ?? 0) + ($r['reversal']['c'] ?? 0)),
            ];

            foreach (['credited', 'charged', 'deducted', 'transactions'] as $k) {
                $walletTotals[$k] += $row[$k];
            }

            $walletDaily[] = $row;
        }

        $walletTotals = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $walletTotals);

        // ---- Totals ----
        $revenue = round($byService->sum('revenue'), 2);
        $cost    = round($byService->sum('cost'), 2);

        return [
            'from'   => $from->toDateString(),
            'to'     => $to->toDateString(),
            'totals' => [
                'revenue'        => $revenue,
                'cost'           => $cost,
                'profit'         => round($revenue - $cost, 2),
                'checks'         => (int) $vRetailer->sum('total'),
                'checks_success' => (int) $vRetailer->sum('ok_count'),
                'kyc'            => (int) $kRetailer->sum('total'),
                'kyc_completed'  => (int) $kRetailer->sum('ok_count'),
                'margin_percent' => $this->marginPercent($revenue, $cost),
                'failed'         => (int) ($vStatus['failed'] ?? 0)
                                    + (int) ($kStatus['failed'] ?? 0) + (int) ($kStatus['cancelled'] ?? 0) + (int) ($kStatus['expired'] ?? 0),
                'in_progress'    => (int) ($vStatus['pending'] ?? 0) + (int) ($kStatus['in_progress'] ?? 0),
            ],
            // Money held in retailer wallets because the provider did not answer (right now, not limited to the dates above)
            'on_hold'     => ['count' => (int) ($hold->c ?? 0), 'amount' => round((float) ($hold->a ?? 0), 2)],
            'alerts'      => ['below_cost' => $this->belowCostAlerts()],
            'by_service'  => $byService,
            'by_retailer' => $byRetailer,
            'daily'       => $daily,
            'monthly'     => $monthly,
            'wallet'      => ['totals' => $walletTotals, 'daily' => $walletDaily],
        ];
    }

    // A text cell that starts with = + - @ can run as a formula when the CSV is opened in Excel
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
