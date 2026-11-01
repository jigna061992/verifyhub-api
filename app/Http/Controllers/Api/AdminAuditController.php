<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Read-only view of audit_logs. Nothing here can edit or delete a row.
class AdminAuditController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $search  = trim((string) $request->query('search', ''));

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->select('a.id', 'a.action', 'a.entity_type', 'a.entity_id', 'a.metadata', 'a.ip_address', 'a.created_at', 'u.name as actor_name', 'u.role as actor_role')
            ->orderByDesc('a.id');

        if ($action = $request->query('action')) {
            $query->where('a.action', $action);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('a.action', 'like', "%{$search}%")
                    ->orWhere('u.name', 'like', "%{$search}%")
                    ->orWhere('a.ip_address', 'like', "%{$search}%");
            });
        }

        if ($from = $request->query('from')) {
            $query->where('a.created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('a.created_at', '<=', $to . ' 23:59:59');
        }

        return $query->paginate($perPage)->through(fn ($r) => [
            'id'          => $r->id,
            'actor'       => $r->actor_name ?? 'System / provider',
            'actor_role'  => $r->actor_role,
            'action'      => $r->action,
            'entity_type' => $r->entity_type,
            'entity_id'   => $r->entity_id,
            'metadata'    => $r->metadata ? json_decode($r->metadata, true) : null,
            'ip_address'  => $r->ip_address,
            'created_at'  => Carbon::parse($r->created_at)->toIso8601String(),
        ]);
    }

    // For the action dropdown
    public function actions()
    {
        return DB::table('audit_logs')->distinct()->orderBy('action')->pluck('action');
    }
}
