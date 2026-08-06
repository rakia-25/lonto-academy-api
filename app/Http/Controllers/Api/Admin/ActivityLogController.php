<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::with('user:id,name,email,role,avatar')
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }

        if ($request->filled('role') && in_array($request->role, ['admin', 'learner'], true)) {
            $query->whereHas('user', fn ($q) => $q->where('role', $request->role));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $logs = $query->paginate(min(100, max(10, (int) $request->get('per_page', 25))));

        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return response()->json([
            'data' => collect($logs->items())->map(fn (ActivityLog $log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'description' => $log->description,
                'properties'  => $log->properties,
                'ip_address'  => $log->ip_address,
                'created_at'  => $log->created_at,
                'subject'     => $log->subject_type && $log->subject_id ? [
                    'type' => class_basename($log->subject_type),
                    'id'   => $log->subject_id,
                ] : null,
                'user' => $log->user ? [
                    'id'     => $log->user->id,
                    'name'   => $log->user->name,
                    'email'  => $log->user->email,
                    'role'   => $log->user->role,
                    'avatar' => $log->user->avatar,
                ] : null,
            ])->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
            'filters' => [
                'actions' => $actions,
            ],
        ]);
    }
}
