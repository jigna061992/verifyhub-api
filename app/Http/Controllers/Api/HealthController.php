<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

// For uptime monitors. Shows nothing but ok/down, no version or server details.
class HealthController extends Controller
{
    public function __invoke()
    {
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'down'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
