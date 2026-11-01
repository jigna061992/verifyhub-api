<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use Illuminate\Http\Request;

// Super admin: every Complete KYC case of every retailer.
// Personal data is NOT shown: no customer name, only the last 4 digits of the mobile and masked step inputs.
class AdminKycCaseController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $status  = $request->query('status');
        $search  = trim((string) $request->query('search', ''));

        $query = KycCase::with('retailer:id,business_name')
            ->latest()
            ->latest('id');

        if (in_array($status, ['in_progress', 'completed', 'failed', 'cancelled', 'expired'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('customer_mobile_last4', $search)
                    ->orWhereHas('retailer', fn ($r) => $r->where('business_name', 'like', "%{$search}%"));
            });
        }

        return $query->paginate($perPage)->through(function ($c) {
            $charged = $c->status === 'completed';

            return [
                'id'             => $c->id,
                'reference'      => $c->reference,
                'business_name'  => $c->retailer?->business_name,
                'mobile_last4'   => $c->customer_mobile_last4,
                'status'         => $c->status,
                'current_step'   => $c->current_step,
                'price'          => $charged ? (float) $c->price : 0.0,
                'margin'         => $charged ? round((float) $c->price - (float) $c->provider_cost, 2) : 0.0,
                'failure_reason' => $c->failure_reason,
                'created_at'     => $c->created_at?->toIso8601String(),
            ];
        });
    }

    // One case with its steps and consent log (for audit). Provider results are never returned.
    public function show(int $id)
    {
        $case = KycCase::with(['retailer:id,business_name', 'steps', 'consents'])->findOrFail($id);

        return response()->json([
            'id'             => $case->id,
            'reference'      => $case->reference,
            'business_name'  => $case->retailer?->business_name,
            'mobile_last4'   => $case->customer_mobile_last4,
            'status'         => $case->status,
            'current_step'   => $case->current_step,
            'price'          => (float) $case->price,
            'provider_cost'  => (float) $case->provider_cost,
            'failure_reason' => $case->failure_reason,
            'created_at'     => $case->created_at?->toIso8601String(),
            'completed_at'   => $case->completed_at?->toIso8601String(),
            'closed_at'      => $case->closed_at?->toIso8601String(),
            'steps' => $case->steps->map(fn ($s) => [
                'step'           => $s->step,
                'status'         => $s->status,
                'attempts'       => (int) $s->attempts,
                'input_masked'   => $s->input_masked,
                'failure_reason' => $s->failure_reason,
                'completed_at'   => $s->completed_at?->toIso8601String(),
            ])->values(),
            'consents' => $case->consents->map(fn ($c) => [
                'type'         => $c->type,
                'purpose'      => $c->purpose,
                'text_version' => $c->text_version,
                'text_hash'    => $c->text_hash,
                'channel'      => $c->channel,
                'ip_address'   => $c->ip_address,
                'accepted_at'  => $c->accepted_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
