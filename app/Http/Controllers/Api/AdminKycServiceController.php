<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VerificationService;

// Super admin: KYC service cards (price, cost, margin, usage, success rate)
class AdminKycServiceController extends Controller
{
    public function index()
    {
        $services = VerificationService::orderBy('id')
            ->withCount([
                'verifications as total_usage',
                'verifications as success_count' => fn ($q) => $q->where('status', 'success'),
                'verifications as failed_count'  => fn ($q) => $q->where('status', 'failed'),
            ])
            ->get()
            ->map(function ($s) {
                // Pending checks are still running, so they are not part of the success rate
                $finished = $s->success_count + $s->failed_count;

                return [
                    'id'            => $s->id,
                    'code'          => $s->code,
                    'name'          => $s->name,
                    'provider'      => $s->provider,
                    'enabled'       => (bool) $s->enabled,
                    // false = screen for this service is not built yet (shown as "Coming soon")
                    // 'built'         => in_array($s->code, VerificationController::SUPPORTED, true),
                    
                    'built' => in_array(
                            $s->code,
                            [...VerificationController::SUPPORTED, 'AADHAAR', 'ESIGN', 'COMPLETE_KYC'],
                            true),
                            'default_price' => (float) $s->default_price,
                    'provider_cost' => (float) $s->provider_cost,
                    'margin'        => round((float) $s->default_price - (float) $s->provider_cost, 2),
                    'total_usage'   => (int) $s->total_usage,
                    'success_count' => (int) $s->success_count,
                    'success_rate'  => $finished > 0 ? round($s->success_count / $finished * 100, 1) : null,
                ];
            });

        return response()->json([
            'enabled_count' => $services->where('enabled', true)->count(),
            'total_count'   => $services->count(),
            'services'      => $services->values(),
        ]);
    }
}
