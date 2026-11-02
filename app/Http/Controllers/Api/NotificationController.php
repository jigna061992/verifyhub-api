<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use Illuminate\Http\Request;

// Every query is limited to the logged-in user, so nobody can read or change someone else's notifications
class NotificationController extends Controller
{
    // GET /notifications?per_page=10&unread=1
    public function index(Request $request)
    {
        $userId  = $request->user()->id;
        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $query = NotificationLog::where('user_id', $userId)->latest('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $page = $query->paginate($perPage)->through(fn ($n) => $this->row($n));

        return response()->json(array_merge($page->toArray(), [
            'unread_count' => $this->unread($userId),
        ]));
    }

    // GET /notifications/unread-count  (small and fast, the bell calls it every minute)
    public function unreadCount(Request $request)
    {
        return response()->json(['count' => $this->unread($request->user()->id)]);
    }

    // POST /notifications/{id}/read
    public function read(Request $request, int $id)
    {
        $userId = $request->user()->id;

        $notification = NotificationLog::where('user_id', $userId)->findOrFail($id);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['unread_count' => $this->unread($userId)]);
    }

    // POST /notifications/read-all
    public function readAll(Request $request)
    {
        NotificationLog::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    private function unread(int $userId): int
    {
        return NotificationLog::where('user_id', $userId)->whereNull('read_at')->count();
    }

    private function row(NotificationLog $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->type,
            'title'      => $n->title,
            'message'    => $n->message,
            'link'       => $n->data['link'] ?? null,
            'read_at'    => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
