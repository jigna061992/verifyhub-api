<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Audit
{
    // Never pass passwords, PAN, GST or tokens in $metadata
    public static function log(Request $request, string $action, string $entityType, int $entityId, array $metadata = []): void
    {
        DB::table('audit_logs')->insert([
            'actor_id'    => $request->user()?->id,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'metadata'    => json_encode($metadata),
            'ip_address'  => $request->ip(),
            'created_at'  => now(),
        ]);
    }
}
