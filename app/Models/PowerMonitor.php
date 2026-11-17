<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PowerMonitor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_latency_ms' => 'float',
            'packet_loss' => 'integer',
            'consecutive_failures' => 'integer',
            'last_checked_at' => 'datetime',
            'last_up_at' => 'datetime',
            'last_down_at' => 'datetime',
            'last_state_changed_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function onu()
    {
        return $this->belongsTo(Onu::class);
    }
}
