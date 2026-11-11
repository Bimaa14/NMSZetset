<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-audit');
        $input = $request->validate([
            'q' => 'nullable|string|max:100', 'action' => 'nullable|string|max:80',
            'actor_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : 'sometimes'],
            'page' => 'nullable|integer|min:1',
        ]);
        $query = AuditLog::where('audit_logs.tenant_id', $request->user()->tenant_id)
            ->leftJoin('users', function ($join) { $join->on('users.id', '=', 'audit_logs.actor_id')->on('users.tenant_id', '=', 'audit_logs.tenant_id')->where('audit_logs.actor_type', 'User'); })
            ->select('audit_logs.*', 'users.name as actor_name');
        if (!empty($input['q'])) {
            $term = '%'.$input['q'].'%';
            $query->where(fn ($q) => $q->where('audit_logs.target', 'like', $term)->orWhere('audit_logs.action', 'like', $term)->orWhere('users.name', 'like', $term));
        }
        if (!empty($input['action'])) $query->where('audit_logs.action', $input['action']);
        if (!empty($input['actor_id'])) $query->where('audit_logs.actor_id', $input['actor_id'])->where('audit_logs.actor_type', 'User');
        if (!empty($input['from'])) $query->where('audit_logs.created_at', '>=', $input['from'].' 00:00:00');
        if (!empty($input['to'])) $query->where('audit_logs.created_at', '<=', $input['to'].' 23:59:59');
        return response()->json($query->orderByDesc('audit_logs.id')->paginate(20));
    }
}
