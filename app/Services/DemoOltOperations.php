<?php

namespace App\Services;

use App\Models\{Olt, Onu};
use Illuminate\Support\Facades\DB;

class DemoOltOperations
{
    public function available(): bool
    {
        return config('nms.demo') && app()->environment('local');
    }

    public function requireAvailable(): void
    {
        abort_unless($this->available(), 503, 'Worker perangkat belum terhubung. Simulator hanya tersedia pada demo lokal.');
    }

    public function refreshPortCounts(Olt $olt): void
    {
        foreach ($olt->ports()->current()->withCount(['onus' => fn ($q) => $q->current(), 'onus as active_count' => fn ($q) => $q->current()->where('phase_state', 'working')])->get() as $port) {
            $port->update(['total_onu' => $port->onus_count, 'online_onu' => $port->active_count]);
        }
    }

    public function settleReboots(int $tenantId): void
    {
        if (!$this->available()) return;
        DB::transaction(function () use ($tenantId) {
            $due = Onu::current()->where('tenant_id', $tenantId)->where('observation_source', 'simulator')->where('phase_state', 'rebooting')
                ->where('reboot_complete_at', '<=', now())->lockForUpdate()->get();
            foreach ($due as $onu) {
                // Reboot does not repair a disconnected fiber or a missing power supply.
                $onu->update(['phase_state' => $onu->reboot_restore_state ?? 'working', 'reboot_complete_at' => null, 'reboot_restore_state' => null]);
            }
            foreach (Olt::where('tenant_id', $tenantId)->whereIn('id', $due->pluck('olt_id')->unique())->get() as $olt) {
                $this->refreshPortCounts($olt);
            }
        });
    }

    public function sync(Olt $olt): void
    {
        $this->requireAvailable();
        $this->settleReboots($olt->tenant_id);
        $this->refreshPortCounts($olt);
        $olt->update(['last_sync_at' => now(), 'telemetry_source' => 'simulator', 'firmware_version' => $olt->firmware_version ?? 'V2.1.0 (simulasi)']);
    }
}
