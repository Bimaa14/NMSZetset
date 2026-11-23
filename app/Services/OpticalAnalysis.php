<?php

namespace App\Services;

use App\Models\Onu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB};

class OpticalAnalysis
{
    private const FRESH_TTL_SECONDS = 300;
    private const STALE_TTL_SECONDS = 1800;

    public function forTenant(int $tenant): array
    {
        $freshKey = 'nms:optical-analysis:'.$tenant;
        $cached = Cache::get($freshKey);
        if (is_array($cached)) {
            return $cached;
        }

        $staleKey = 'nms:optical-analysis:stale:'.$tenant;
        $lock = Cache::lock('nms:optical-analysis:lock:'.$tenant, 60);

        // Prevent cache stampede: if another request is recomputing and we have a recent snapshot, serve it immediately.
        if (! $lock->get()) {
            $stale = Cache::get($staleKey);
            if (is_array($stale)) {
                return $stale;
            }
        }

        try {
            $cached = Cache::get($freshKey);
            if (is_array($cached)) {
                return $cached;
            }

            $result = $this->compute($tenant);
            Cache::put($freshKey, $result, self::FRESH_TTL_SECONDS);
            Cache::put($staleKey, $result, self::STALE_TTL_SECONDS);

            return $result;
        } finally {
            optional($lock)->release();
        }
    }

    private function compute(int $tenant): array
    {
        $now = now();
        $cutoffRecent = $now->copy()->subMinutes(15);
        $cutoffDay = $now->copy()->subDay();

        $rows = Onu::current()
            ->where('onus.tenant_id', $tenant)
            ->get(['id', 'olt_id', 'olt_port_id', 'observation_source', 'rx_power', 'optical_observed_at']);

        if ($rows->isEmpty()) {
            return [
                'items' => [],
                'degraded_ids' => [],
                'correlated_pons' => 0,
                'calculated_at' => $now->toIso8601String(),
                'baseline_hours' => 24,
                'recent_minutes' => 15,
                'drop_db' => 3,
            ];
        }

        // Index-only covering scan on (calculated_at, onu_id, observation_source, rx_power)
        $baselineRows = DB::table('onu_optical_logs')
            ->select('onu_id', 'observation_source')
            ->selectRaw('AVG(rx_power) as baseline_rx, COUNT(*) as samples')
            ->whereBetween('calculated_at', [$cutoffDay, $cutoffRecent])
            ->whereNotNull('rx_power')
            ->groupBy('onu_id', 'observation_source')
            ->get();

        $baselines = [];
        foreach ($baselineRows as $b) {
            $baselines[$b->onu_id.'|'.$b->observation_source] = [
                'baseline_rx' => $b->baseline_rx !== null ? (float) $b->baseline_rx : null,
                'samples' => (int) $b->samples,
            ];
        }

        $items = [];
        $ports = [];
        foreach ($rows as $r) {
            $base = $baselines[$r->id.'|'.$r->observation_source] ?? null;
            $baselineRx = $base['baseline_rx'] ?? null;
            $samples = $base['samples'] ?? 0;

            $stale = ! $r->optical_observed_at || Carbon::parse($r->optical_observed_at)->lt($cutoffRecent);
            $delta = $r->rx_power !== null && $baselineRx !== null ? round(((float) $r->rx_power) - $baselineRx, 2) : null;
            $degraded = ! $stale && $samples >= 3 && $delta !== null && $delta <= -3;

            $items[$r->id] = [
                'baseline_rx' => $baselineRx === null ? null : round($baselineRx, 2),
                'baseline_samples' => $samples,
                'delta_db' => $delta,
                'optical_stale' => $stale,
                'degraded' => $degraded,
                'severity' => $degraded ? ($delta <= -6 ? 'critical' : 'warning') : null,
                'correlated_count' => 0,
            ];
            if ($degraded) {
                $ports[$r->olt_port_id][] = $r->id;
            }
        }

        foreach ($ports as $ids) {
            $count = count($ids);
            foreach ($ids as $id) {
                $items[$id]['correlated_count'] = $count;
            }
        }

        return [
            'items' => $items,
            'degraded_ids' => array_keys(array_filter($items, fn ($r) => $r['degraded'])),
            'correlated_pons' => count(array_filter($ports, fn ($ids) => count($ids) >= 2)),
            'calculated_at' => $now->toIso8601String(),
            'baseline_hours' => 24,
            'recent_minutes' => 15,
            'drop_db' => 3,
        ];
    }
}
