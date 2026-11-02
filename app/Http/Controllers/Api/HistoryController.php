<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Verification;
use App\Models\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Retailer "History" screen: single checks (PAN, Bank, GST) AND Complete KYC cases in one list.
 *
 * Single checks live in `verifications`, Complete KYC lives in `kyc_cases`, so the two tables are
 * joined with a UNION that only carries (id, created_at, kind). That keeps sorting and paging correct.
 * The full rows are loaded afterwards for the ids of the current page only.
 *
 * Always filtered by the logged-in retailer. A retailer id is never taken from the request.
 */
class HistoryController extends Controller
{
    // The status filter of the screen -> the real status values of a Complete KYC case
    private const KYC_STATUSES = [
        'pending' => ['in_progress'],
        'success' => ['completed'],
        'failed'  => ['failed', 'cancelled', 'expired'],
    ];

    // Service dropdown: the single checks plus "Complete KYC". Disabled services stay listed, their old rows still exist.
    public function services(Request $request)
    {
        $this->retailer($request);

        $singles = VerificationService::whereIn('code', VerificationController::ALL)
            ->orderBy('id')
            ->get(['code', 'name'])
            ->map(fn ($s) => ['code' => $s->code, 'name' => $s->name]);

        return $singles->push(['code' => $this->bundleCode(), 'name' => 'Complete KYC'])->values();
    }

    public function index(Request $request)
    {
        $retailer = $this->retailer($request);
        $perPage  = min(max((int) $request->query('per_page', 10), 1), 100);

        $page = DB::query()
            ->fromSub($this->union($retailer->id, $request->query('status'), $request->query('service')), 'h')
            ->orderByDesc('created_at')
            ->orderByDesc('kind')
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = collect($page->items());

        $verifications = Verification::with('service:id,code,name')
            ->whereIn('id', $items->where('kind', 'verification')->pluck('id'))
            ->get()
            ->keyBy('id');

        $cases = KycCase::whereIn('id', $items->where('kind', 'kyc')->pluck('id'))
            ->get()
            ->keyBy('id');

        return $page->through(fn ($r) => $r->kind === 'kyc'
            ? $this->kycRow($cases[$r->id])
            : $this->verificationRow($verifications[$r->id]));
    }

    /* ------------------------------------------------------------------ */

    private function union(int $retailerId, ?string $status, ?string $service)
    {
        $checks = DB::table('verifications')
            ->where('retailer_id', $retailerId)
            ->select('id', 'created_at')
            ->selectRaw("'verification' as kind");

        $cases = DB::table('kyc_cases')
            ->where('retailer_id', $retailerId)
            ->select('id', 'created_at')
            ->selectRaw("'kyc' as kind");

        if (isset(self::KYC_STATUSES[$status])) {
            $checks->where('status', $status);
            $cases->whereIn('status', self::KYC_STATUSES[$status]);
        }

        if ($service) {
            if (strtoupper($service) === $this->bundleCode()) {
                $checks->whereRaw('1 = 0');
            } else {
                $checks->whereIn('service_id', VerificationService::where('code', $service)->pluck('id'));
                $cases->whereRaw('1 = 0');
            }
        }

        return $checks->unionAll($cases);
    }

    private function verificationRow(Verification $v): array
    {
        return [
            'kind'             => 'verification',
            'id'               => $v->id,
            'service_code'     => $v->service?->code,
            'service_name'     => $v->service?->name,
            'customer_name'    => $v->meta['customer_name'] ?? null,
            'status'           => $v->status,
            'input_masked'     => $v->input_masked,
            'price'            => (float) $v->price,
            'charged'          => $v->status === 'success' ? (float) $v->price : 0.0,
            'failure_reason'   => $v->failure_reason,
            'created_at'       => $v->created_at?->toIso8601String(),
            'receipt_url'      => $v->status === 'success' ? "/retailer/verifications/{$v->id}/receipt" : null,
            'receipt_filename' => 'receipt.pdf',
            'detail_path'      => null,
        ];
    }

    private function kycRow(KycCase $c): array
    {
        return [
            'kind'             => 'kyc',
            'id'               => $c->id,
            'service_code'     => $this->bundleCode(),
            'service_name'     => 'Complete KYC',
            'customer_name'    => $c->customer_name,
            'status'           => $c->status,   // in_progress | completed | failed | cancelled | expired
            'input_masked'     => 'XXXXXX' . $c->customer_mobile_last4,
            'price'            => (float) $c->price,
            'charged'          => $c->status === 'completed' ? (float) $c->price : 0.0,
            'failure_reason'   => $c->failure_reason,
            'created_at'       => $c->created_at?->toIso8601String(),
            'receipt_url'      => $c->status === 'completed' ? "/retailer/kyc/{$c->id}/report" : null,
            'receipt_filename' => 'kyc-report.pdf',
            'detail_path'      => "/retailer/kyc/{$c->id}",
        ];
    }

    private function bundleCode(): string
    {
        return strtoupper((string) config('kyc.service_code', 'COMPLETE_KYC'));
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }
}
