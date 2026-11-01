<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Retailer;
use App\Models\Verification;
use App\Models\VerificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

// Super admin: revenue, cost and profit for a date range.
// Revenue is counted only for successful single checks and completed Complete KYC cases
// (failed / cancelled / expired cases return the money to the retailer, so they earn nothing).
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

    /* ------------------------------------------------------------------ */

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
            $s['success_rate'] = $s['total'] > 0 ? round($s['success'] / $s['total'] * 100, 1) : null;

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
            ];
        })->sortByDesc('revenue')->values();

        // ---- Daily series (every day of the range, also days with no activity) ----
        $daily = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();

            $revenue = (float) ($vDaily[$key]->revenue ?? 0) + (float) ($kDaily[$key]->revenue ?? 0);
            $cost    = (float) ($vDaily[$key]->cost ?? 0) + (float) ($kDaily[$key]->cost ?? 0);

            $daily[] = ['date' => $key, 'revenue' => round($revenue, 2), 'profit' => round($revenue - $cost, 2)];
        }

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
            ],
            'by_service'  => $byService,
            'by_retailer' => $byRetailer,
            'daily'       => $daily,
        ];
    }

    // A text cell that starts with = + - @ can run as a formula when the CSV is opened in Excel
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
