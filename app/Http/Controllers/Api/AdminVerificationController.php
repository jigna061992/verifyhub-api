<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Verification;
use Illuminate\Http\Request;

// Super admin: all verifications of all retailers (no personal result data, only masked input)
class AdminVerificationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 100);
        $status  = $request->query('status');
        $code    = $request->query('service');
        $search  = trim((string) $request->query('search', ''));

        $query = Verification::with(['retailer:id,business_name', 'service:id,code,name'])
            ->latest()
            ->latest('id');

        if (in_array($status, ['pending', 'success', 'failed'], true)) {
            $query->where('status', $status);
        }

        if ($code) {
            $query->whereHas('service', fn ($s) => $s->where('code', $code));
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('input_masked', 'like', "%{$search}%")
                    ->orWhereHas('retailer', fn ($r) => $r->where('business_name', 'like', "%{$search}%"));
            });
        }

        return $query->paginate($perPage)->through(function ($v) {
            $charged = $v->status === 'success';

            return [
                'id'             => $v->id,
                'business_name'  => $v->retailer?->business_name,
                'service_code'   => $v->service?->code,
                'service_name'   => $v->service?->name,
                'input_masked'   => $v->input_masked,
                'status'         => $v->status,
                'price'          => $charged ? (float) $v->price : 0.0,
                'margin'         => $charged ? round((float) $v->price - (float) $v->provider_cost, 2) : 0.0,
                'failure_reason' => $v->failure_reason,
                'created_at'     => $v->created_at?->toIso8601String(),
            ];
        });
    }
}
