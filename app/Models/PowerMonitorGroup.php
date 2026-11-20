<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PowerMonitorGroup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function monitors()
    {
        return $this->hasMany(PowerMonitor::class, 'power_monitor_group_id');
    }

    public static function ensureDefaultForTenant(int $tenantId): self
    {
        $existing = self::where('tenant_id', $tenantId)->orderBy('sort_order')->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return self::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Listrik'],
            [
                'color_hex' => '#f59e0b',
                'description' => 'Pemantauan listrik PLN via Ping IP & live status ONU',
                'sort_order' => 1,
            ]
        );
    }
}
