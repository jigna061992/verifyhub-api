<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Verification;
use App\Models\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Unified history: single verifications + Complete KYC cases in one paginated list,
 * sorted newest-first. Used by the retailer History page.
 */
class RetailerHistoryController extends Controller
{
    public function index(Request $request)
    {
        $retailer = $this->retailer($request);
        $perPage  = min(max((int) $request->query('per_page', 10), 1), 100);
        $page     = max((int) $request->query('page', 1), 1);

        // Optional filters
        $statusFilter  = $request->query('status');   // success|failed|pending for verifications; completed|failed|cancelled etc for kyc
        $serviceFilter = $request->query('service');  // service code, only applies to verifications

        // ── Single verifications ────────────────────────────────────────────
        $vQuery = Verification::with('service:id,code,name')
            ->where('retailer_id', $retailer->id);

        if ($serviceFilter) {
            // KYC rows are excluded when a service code filter is active
            $vQuery->whereHas('service', fn ($s) => $s->where('code', $serviceFilter));
        }

        if (in_array($statusFilter, ['pending', 'success', 'failed'], true)) {
            $vQuery->where('status', $statusFilter);
        }

        $verifications = $vQuery->get()->map(fn ($v) => [
            'id'           => 'v-' . $v->id,
            'raw_id'       => $v->id,
            'type'         => 'verification',
            'service_name' => $v->service?->name ?? 'Verification',
            'service_code' => $v->service?->code,
            'identifier'   => $v->input_masked,
            'status'       => $v->status,
            'charged'      => $v->status === 'success' ? (float) $v->price : 0.0,
            'note'         => $v->failure_reason ?? '',
            'created_at'   => $v->created_at?->toIso8601String(),
            'receipt_url'  => $v->status === 'success' ? '/retailer/verifications/' . $v->id . '/receipt' : null,
            'detail_url'   => null,
        ]);

        // ── Complete KYC cases ──────────────────────────────────────────────
        // Only include if no service code filter is active (KYC is its own service type)
        $kycRows = collect();
        if (! $serviceFilter) {
            $kycQuery = KycCase::where('retailer_id', $retailer->id);

            // Map frontend status filter to KYC statuses
            if ($statusFilter === 'success') {
                $kycQuery->where('status', 'completed');
            } elseif ($statusFilter === 'failed') {
                $kycQuery->whereIn('status', ['failed', 'cancelled', 'expired']);
            } elseif ($statusFilter === 'pending') {
                $kycQuery->where('status', 'in_progress');
            }

            $kycRows = $kycQuery->get()->map(fn ($c) => [
                'id'           => 'k-' . $c->id,
                'raw_id'       => $c->id,
                'type'         => 'kyc',
                'service_name' => 'Complete KYC',
                'service_code' => 'COMPLETE_KYC',
                'identifier'   => 'XXXXXX' . $c->customer_mobile_last4,
                'status'       => match ($c->status) {
                    'completed'   => 'success',
                    'failed',
                    'cancelled',
                    'expired'     => 'failed',
                    default       => 'pending',
                },
                'charged'      => $c->status === 'completed' ? (float) $c->price : 0.0,
                'note'         => $c->failure_reason ?? ($c->status === 'in_progress' ? 'In progress' : ''),
                'created_at'   => $c->created_at?->toIso8601String(),
                'receipt_url'  => null,
                'detail_url'   => '/retailer/kyc/' . $c->id,
            ]);
        }

        // ── Merge, sort newest-first, paginate ─────────────────────────────
        $all = $verifications->merge($kycRows)
            ->sortByDesc('created_at')
            ->values();

        $total  = $all->count();
        $offset = ($page - 1) * $perPage;
        $items  = $all->slice($offset, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator->toArray());
    }

    // ── Available services list for the filter dropdown ───────────────────
    public function services(Request $request)
    {
        $this->retailer($request); // ensure authenticated retailer

        $services = VerificationService::whereIn('code', \App\Http\Controllers\Api\VerificationController::ALL)
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['code', 'name'])
            ->map(fn ($s) => ['code' => $s->code, 'name' => $s->name]);

        return response()->json($services);
    }

    private function retailer(Request $request)
    {
        $retailer = $request->user()->retailer;

        abort_if(! $retailer, 404, 'Retailer profile not found.');

        return $retailer;
    }
}
