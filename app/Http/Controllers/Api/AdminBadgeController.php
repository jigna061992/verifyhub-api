<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTopupRequest;

// Small numbers shown as badges in the sidebar
class AdminBadgeController extends Controller
{
    public function index()
    {
        return response()->json([
            'pending_topups' => WalletTopupRequest::where('status', 'pending')->count(),
        ]);
    }
}
