<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycCase;
use App\Models\Verification;
use App\Models\VerificationService;
use App\Models\WalletTopupRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RetailerController extends Controller
{
    // Dashboard numbers for the logged-in retailer (only their own data)
    public function dashboardStats(Request $request)
    {
        $retailer = $request->user()->retailer;

        if (! $retailer) {
            return response()->json(['message' => 'Retailer profile not found.'], 404);
        }

        $wallet = $retailer->wallet;

        // Retailer specific price overrides, keyed by service id
        $customPrices = DB::table('retailer_pricings')
            ->where('retailer_id', $retailer->id)
            ->pluck('custom_price', 'service_id');

        $services = VerificationService::where('enabled', true)->get()->map(fn ($s) => [
            'code'  => $s->code,
            'name'  => $s->name,
            // Server decides the price, the frontend only displays it
            'price' => (float) ($customPrices[$s->id] ?? $s->default_price),
        ]);

        $topups = WalletTopupRequest::where('retailer_id', $retailer->id);
        $checks = Verification::where('retailer_id', $retailer->id);
        $cases  = KycCase::where('retailer_id', $retailer->id);

        // Single checks and Complete KYC cases are counted together
        $total   = (clone $checks)->count() + (clone $cases)->count();
        $success = (clone $checks)->where('status', 'success')->count() + (clone $cases)->where('status', 'completed')->count();
        $spend   = (float) (clone $checks)->where('status', 'success')->sum('price')
                 + (float) (clone $cases)->where('status', 'completed')->sum('price');

        return response()->json([
            'business_name' => $retailer->business_name,
            'wallet' => [
                'available' => (float) ($wallet?->available_balance ?? 0),
                'reserved'  => (float) ($wallet?->reserved_balance ?? 0),
            ],
            'topups' => [
                'pending_count'  => (clone $topups)->where('status', 'pending')->count(),
                'approved_total' => (float) (clone $topups)->where('status', 'approved')->sum('amount'),
            ],
            'verifications' => [
                'total'        => $total,
                'success_rate' => $total ? round($success / $total * 100, 1) : null,
                'spend'        => round($spend, 2),
            ],
            'services' => $services,
        ]);
    }
}
