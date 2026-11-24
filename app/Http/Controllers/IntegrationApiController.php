<?php

namespace App\Http\Controllers;

use App\Models\{CustomerGroup, Olt, OltOperation, Onu, PowerMonitor, PowerMonitorGroup};
use App\Services\Olt\OltOperationService;
use App\Services\{DemoOltOperations, AuditRecorder};
use Illuminate\Http\Request;

class IntegrationApiController extends Controller
{
    public function olts(Request $r)
    {
        $tenantId = (int) $r->user()->tenant_id;
        $olts = Olt::where('tenant_id', $tenantId)->orderBy('id')->get();
        $breakdownByOlt = $this->oltStatusCounts($tenantId);

        $data = $olts->map(function (Olt $olt) use ($breakdownByOlt) {
            $counts = $breakdownByOlt[$olt->id] ?? $this->emptyOltCounts();
            $arr = $olt->toArray();
            $arr['onus_count'] = $counts['total_onus'];
            $arr['online_onu'] = $counts['online'];
            $arr['non_online_onu'] = $counts['non_online'];
            $arr['offline_onu'] = $counts['offline'];
            $arr['dying_gasp_onu'] = $counts['dying_gasp'];
            $arr['los_onu'] = $counts['los'];
            $arr['power_off_onu'] = $counts['power_off'];
            $arr['other_non_online_onu'] = $counts['other'];

            return $arr;
        })->values();

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function summary(Request $r)
    {
        $d = $r->validate(['top' => 'nullable|integer|min:1|max:50']);
        $topLimit = (int) ($d['top'] ?? 10);
        $tenantId = (int) $r->user()->tenant_id;

        $olts = Olt::where('tenant_id', $tenantId)->orderBy('id')->get();
        $breakdownByOlt = $this->oltStatusCounts($tenantId);

        $totals = $this->emptyOltCounts();
        $perOlt = [];
        $readableOlts = 0;

        foreach ($olts as $olt) {
            if ($olt->status === 'Online' && empty($olt->last_connection_error)) {
                $readableOlts++;
            }
            $counts = $breakdownByOlt[$olt->id] ?? $this->emptyOltCounts();
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $counts[$k];
            }

            $perOlt[] = [
                'olt_id' => $olt->id,
                'name' => $olt->name,
                'chassis_type' => $olt->chassis_type,
                'status' => $olt->status,
                'total_onus' => $counts['total_onus'],
                'online' => $counts['online'],
                'non_online' => $counts['non_online'],
                'offline' => $counts['offline'],
                'dying_gasp' => $counts['dying_gasp'],
                'los' => $counts['los'],
                'power_off' => $counts['power_off'],
                'other' => $counts['other'],
                'last_sync_at' => $olt->last_sync_at?->toIso8601String(),
            ];
        }

        usort($perOlt, fn ($a, $b) => ($b['non_online'] <=> $a['non_online']) ?: ($b['total_onus'] <=> $a['total_onus']));

        $powerRows = PowerMonitor::where('tenant_id', $tenantId)->where('is_enabled', true)->get(['status']);
        $nowWib = now()->timezone('Asia/Jakarta');

        return response()->json([
            'status' => 'success',
            'checked_at' => $nowWib->toIso8601String(),
            'checked_at_wib' => $nowWib->format('d/m/Y H:i:s').' WIB',
            'total_olts' => $olts->count(),
            'readable_olts' => $readableOlts,
            'total_onus' => $totals['total_onus'],
            'online_onus' => $totals['online'],
            'non_online_onus' => $totals['non_online'],
            'status_breakdown' => [
                'online' => $totals['online'],
                'offline' => $totals['offline'],
                'dying_gasp' => $totals['dying_gasp'],
                'los' => $totals['los'],
                'power_off' => $totals['power_off'],
                'other' => $totals['other'],
            ],
            'top_non_online_olts' => array_slice($perOlt, 0, $topLimit),
            'power_monitoring' => [
                'total' => $powerRows->count(),
                'up' => $powerRows->where('status', 'up')->count(),
                'warning' => $powerRows->where('status', 'warning')->count(),
                'down' => $powerRows->where('status', 'down')->count(),
                'unknown' => $powerRows->where('status', 'unknown')->count(),
            ],
        ]);
    }

    public function power(Request $r)
    {
        $d = $r->validate([
            'category' => 'nullable|string|max:100',
            'status' => 'nullable|in:all,up,warning,down,unknown',
        ]);
        $tenantId = (int) $r->user()->tenant_id;

        $groups = PowerMonitorGroup::where('tenant_id', $tenantId)->orderBy('sort_order')->orderBy('name')->get();
        $monitors = PowerMonitor::where('tenant_id', $tenantId)
            ->with(['group:id,name', 'onu:id,olt_id,olt_port_id,onu_index,name,sn,phase_state', 'onu.olt:id,name', 'onu.port:id,interface_name'])
            ->orderByRaw("CASE WHEN status = 'down' THEN 0 WHEN status = 'warning' THEN 1 WHEN status = 'unknown' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        $catFilter = mb_strtolower(trim((string) ($d['category'] ?? '')));
        $statusFilter = $d['status'] ?? 'all';

        $filtered = $monitors->filter(function (PowerMonitor $m) use ($catFilter, $statusFilter) {
            if ($catFilter !== '') {
                $catName = mb_strtolower((string) ($m->group?->name ?? $m->group_name ?? ''));
                if (! str_contains($catName, $catFilter)) {
                    return false;
                }
            }
            if ($statusFilter !== 'all' && $m->status !== $statusFilter) {
                return false;
            }

            return true;
        })->values();

        return response()->json([
            'status' => 'success',
            'summary' => [
                'total' => $filtered->count(),
                'up' => $filtered->where('status', 'up')->count(),
                'warning' => $filtered->where('status', 'warning')->count(),
                'down' => $filtered->where('status', 'down')->count(),
                'unknown' => $filtered->where('status', 'unknown')->count(),
            ],
            'categories' => $groups->map(function (PowerMonitorGroup $g) use ($monitors) {
                $items = $monitors->where('power_monitor_group_id', $g->id);
                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'description' => $g->description,
                    'total' => $items->count(),
                    'up' => $items->where('status', 'up')->count(),
                    'warning' => $items->where('status', 'warning')->count(),
                    'down' => $items->where('status', 'down')->count(),
                ];
            })->values(),
            'data' => $filtered->map(fn (PowerMonitor $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'category' => $m->group?->name ?? $m->group_name,
                'location' => $m->location,
                'monitor_type' => $m->monitor_type,
                'ip_address' => $m->ip_address,
                'status' => $m->status,
                'is_enabled' => $m->is_enabled,
                'latency_ms' => $m->last_latency_ms,
                'packet_loss' => $m->packet_loss,
                'last_error' => $m->last_error,
                'last_checked_at' => $m->last_checked_at?->toIso8601String(),
                'last_down_at' => $m->last_down_at?->toIso8601String(),
                'last_up_at' => $m->last_up_at?->toIso8601String(),
                'onu' => $m->onu ? [
                    'onu_id' => $m->onu->id,
                    'name' => $m->onu->name,
                    'sn' => $m->onu->sn,
                    'phase_state' => $m->onu->phase_state,
                    'olt_name' => $m->onu->olt?->name,
                    'interface' => $m->onu->port
                        ? preg_replace('/^(gpon|epon)-olt_/', '$1-onu_', $m->onu->port->interface_name).':'.$m->onu->onu_index
                        : null,
                ] : null,
            ]),
        ]);
    }

    private function row(Onu $onu, ?PowerMonitor $powerMonitor = null): array
    {
        $statusLabel = match ($onu->phase_state) {
            'working' => 'Online (Working)',
            'los' => 'LOS (Putus Kabel / Redaman Hilang)',
            'dying-gasp' => 'Dying Gasp (Indikasi Mati Listrik)',
            'poweroff' => 'Power Off',
            'offline' => 'Offline',
            'rebooting' => 'Rebooting',
            default => strtoupper((string) $onu->phase_state),
        };

        $payload = [
            'onu_id' => $onu->id,
            'name' => $onu->name,
            'sn' => $onu->sn,
            'description' => $onu->description,
            'tags' => $onu->tags->pluck('tag_name'),
            'olt_name' => $onu->olt->name,
            'olt_type' => $onu->olt->chassis_type,
            'interface' => preg_replace('/^(gpon|epon)-olt_/', '$1-onu_', $onu->port->interface_name).':'.$onu->onu_index,
            'phase_state' => $onu->phase_state,
            'is_online' => $onu->phase_state === 'working',
            'status_label' => $statusLabel,
            'rx_power_dbm' => $onu->rx_power,
            'olt_rx_power_dbm' => $onu->olt_rx_power,
            'pppoe_username' => $onu->pppoe_user,
            'vlan' => $onu->vlan_id,
            'vlan_ids' => $onu->vlan_ids ?? [],
            'onu_type' => $onu->onu_type,
            'admin_state' => $onu->admin_state,
            'last_online_at' => $onu->last_online_at?->toIso8601String(),
            'last_offline_at' => $onu->last_offline_at?->toIso8601String(),
            'signal_quality' => $onu->rx_power === null ? 'Belum tersedia' : ($onu->rx_power <= config('nms.bad_rx_threshold') ? 'Buruk' : 'Normal'),
            'last_sync' => $onu->olt->last_sync_at,
            'state_observed_at' => $onu->state_observed_at,
            'optical_observed_at' => $onu->optical_observed_at,
        ];

        if ($powerMonitor) {
            $payload['power_monitor'] = [
                'id' => $powerMonitor->id,
                'name' => $powerMonitor->name,
                'category' => $powerMonitor->group?->name ?? $powerMonitor->group_name,
                'status' => $powerMonitor->status,
                'ip_address' => $powerMonitor->ip_address,
                'latency_ms' => $powerMonitor->last_latency_ms,
                'last_checked_at' => $powerMonitor->last_checked_at?->toIso8601String(),
            ];
        }

        return $payload;
    }

    public function show(Request $r, int $id)
    {
        $tenantId = (int) $r->user()->tenant_id;
        $onu = Onu::current()->where('tenant_id', $tenantId)->with(['tags', 'olt', 'port'])->findOrFail($id);
        $groups = CustomerGroup::where('tenant_id', $tenantId)->get();
        $powerMonitor = PowerMonitor::where('tenant_id', $tenantId)->where('onu_id', $onu->id)->with('group:id,name')->first();
        $row = $this->row($onu, $powerMonitor);
        $row['customer_groups'] = $groups
            ->filter(fn (CustomerGroup $g) => $g->matchesOnu($onu))
            ->map(fn (CustomerGroup $g) => $g->name)
            ->values();

        return response()->json([
            'status' => 'success',
            'source' => 'stored',
            'data' => $row,
        ]);
    }

    public function search(Request $r)
    {
        $d = $r->validate(['q' => 'required|string|min:1|max:120']);
        $tenantId = (int) $r->user()->tenant_id;
        $tokens = $this->extractSearchTokens($d['q']);

        $query = Onu::current()
            ->where('tenant_id', $tenantId)
            ->with(['tags', 'olt', 'port']);

        foreach ($tokens as $tok) {
            $like = '%'.$tok.'%';
            $query->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('sn', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('pppoe_user', 'like', $like)
                ->orWhereHas('olt', fn ($o) => $o->where('name', 'like', $like))
                ->orWhereHas('tags', fn ($t) => $t->where('tag_name', 'like', $like)));
        }

        $rows = $query->orderBy('id')->paginate(100);
        $onuIds = $rows->getCollection()->pluck('id')->all();
        $powerByOnu = $onuIds
            ? PowerMonitor::where('tenant_id', $tenantId)->whereIn('onu_id', $onuIds)->with('group:id,name')->get()->keyBy('onu_id')
            : collect();

        return response()->json([
            'status' => 'success',
            'query' => $d['q'],
            'tokens' => $tokens,
            'count' => $rows->count(),
            'total' => $rows->total(),
            'next_page_url' => $rows->nextPageUrl(),
            'data' => $rows->getCollection()->map(fn ($o) => $this->row($o, $powerByOnu->get($o->id))),
        ]);
    }

    /**
     * Split multi-word / conversational queries into meaningful search tokens.
     *
     * @return list<string>
     */
    private function extractSearchTokens(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
        if (count($parts) <= 1) {
            return $parts;
        }

        $fillers = ['tolong', 'cek', 'cekin', 'status', 'onu', 'ont', 'modem', 'client', 'klien', 'pelanggan', 'user', 'dong', 'min', 'bro', 'pak', 'bu', 'cari', 'lihat', 'info', 'data', 'yang', 'di'];
        $meaningful = array_values(array_filter($parts, fn ($p) => ! in_array(mb_strtolower($p), $fillers, true)));

        return array_slice($meaningful ?: $parts, 0, 8);
    }

    public function badRx(Request $r)
    {
        $d = $r->validate(['threshold' => 'nullable|numeric|min:-50|max:0']);
        $threshold = (float) ($d['threshold'] ?? config('nms.bad_rx_threshold'));
        $rows = Onu::current()
            ->where('tenant_id', $r->user()->tenant_id)
            ->where('rx_power', '<=', $threshold)
            ->with(['tags', 'olt', 'port'])
            ->orderBy('rx_power')
            ->paginate(100);

        return response()->json([
            'status' => 'success',
            'threshold_dbm' => $threshold,
            'total_bad_onu' => $rows->total(),
            'data' => $rows->getCollection()->map(fn ($o) => $this->row($o)),
            'next_page_url' => $rows->nextPageUrl(),
        ]);
    }

    public function live(Request $r, int $id, OltOperationService $service)
    {
        $onu = Onu::current()->where('tenant_id', $r->user()->tenant_id)->with(['tags', 'olt', 'port'])->findOrFail($id);
        $op = $service->enqueue($onu->olt, 'inventory', $r->user(), $r->ip());

        return response()->json([
            'status' => 'accepted',
            'realtime' => false,
            'source' => 'stored',
            'message' => 'Pembacaan perangkat dijadwalkan. Poll endpoint operasi untuk hasil terbaru.',
            'operation_id' => $op->id,
            'operation_url' => url('/api/v1/operations/'.$op->id),
            'data' => $this->row($onu),
        ], 202);
    }

    public function operation(Request $r, int $id)
    {
        return OltOperation::where('tenant_id', $r->user()->tenant_id)->findOrFail($id);
    }

    public function reboot(Request $r, int $id)
    {
        return app(OnuController::class)->reboot($r, $id, app(DemoOltOperations::class), app(AuditRecorder::class));
    }

    /**
     * Aggregate current ONU counts per OLT using one grouped SQL query.
     *
     * @return array<int, array<string, int>>
     */
    private function oltStatusCounts(int $tenantId): array
    {
        $rows = Onu::current()
            ->where('onus.tenant_id', $tenantId)
            ->selectRaw('onus.olt_id, onus.phase_state, COUNT(*) as aggregate_count')
            ->groupBy('onus.olt_id', 'onus.phase_state')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $oltId = (int) $row->olt_id;
            $count = (int) $row->aggregate_count;
            $phase = (string) $row->phase_state;

            if (! isset($result[$oltId])) {
                $result[$oltId] = $this->emptyOltCounts();
            }

            $result[$oltId]['total_onus'] += $count;
            match ($phase) {
                'working' => $result[$oltId]['online'] += $count,
                'offline' => $result[$oltId]['offline'] += $count,
                'dying-gasp' => $result[$oltId]['dying_gasp'] += $count,
                'los' => $result[$oltId]['los'] += $count,
                'poweroff' => $result[$oltId]['power_off'] += $count,
                default => $result[$oltId]['other'] += $count,
            };
            if ($phase !== 'working') {
                $result[$oltId]['non_online'] += $count;
            }
        }

        return $result;
    }

    /** @return array<string, int> */
    private function emptyOltCounts(): array
    {
        return [
            'total_onus' => 0,
            'online' => 0,
            'non_online' => 0,
            'offline' => 0,
            'dying_gasp' => 0,
            'los' => 0,
            'power_off' => 0,
            'other' => 0,
        ];
    }
}
