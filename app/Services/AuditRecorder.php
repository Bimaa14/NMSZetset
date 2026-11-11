<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditRecorder
{
    // Only record a non-secret target identifier. Never store request bodies or credentials.
    public function record(Request $request, string $action, string $target, ?int $oltId = null): AuditLog
    {
        return AuditLog::create([
            'tenant_id' => $request->user()->tenant_id,
            'actor_type' => $request->attributes->has('api_token')?'API_Token':'User', 'actor_id' => $request->attributes->get('api_token')?->id ?? $request->user()->id,
            'olt_id' => $oltId, 'action' => $action, 'target' => mb_substr($target, 0, 255),
            'ip_address' => $request->ip(), 'created_at' => now(),
        ]);
    }
}
