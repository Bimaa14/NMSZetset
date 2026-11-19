<?php

namespace App\Http\Controllers;

use App\Models\{CustomerGroup, Olt, OltBackup, Onu, OnuDetailSnapshot};
use App\Services\AuditRecorder;
use App\Services\Olt\{OltOperationService, ZteOutputParser};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerGroupController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->allows('read'), 403);

        return response()->json($this->payload($request->user()->tenant_id))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $data = $this->validateInput($request, $tenantId);

        $group = CustomerGroup::create($data + ['tenant_id' => $tenantId]);
        $audit->record($request, 'customer_group.create', $group->name.' (VLAN '.implode(', ', $group->vlan_ids).')');

        return response()->json($this->payload($tenantId) + [
            'message' => 'Grup pelanggan '.$group->name.' berhasil ditambahkan.',
            'group' => $group,
        ], 201);
    }

    public function update(Request $request, int $id, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $group = CustomerGroup::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $this->validateInput($request, $tenantId, $group);

        $group->update($data);
        $audit->record($request, 'customer_group.update', $group->name.' (VLAN '.implode(', ', $group->vlan_ids).')');

        return response()->json($this->payload($tenantId) + [
            'message' => 'Grup pelanggan '.$group->name.' diperbarui.',
            'group' => $group->fresh(),
        ]);
    }

    public function destroy(Request $request, int $id, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $group = CustomerGroup::where('tenant_id', $tenantId)->findOrFail($id);
        $name = $group->name;
        $group->delete();

        $audit->record($request, 'customer_group.delete', $name);

        return response()->json($this->payload($tenantId) + [
            'message' => 'Grup pelanggan '.$name.' dihapus.',
        ]);
    }

    /**
     * Backfill ONU VLANs immediately from existing WAN detail snapshots & OLT backups,
     * and enqueue background `names` polling on real ZTE OLTs to fetch fresh VLAN configs.
     */
    public function syncVlans(Request $request, OltOperationService $ops, ZteOutputParser $parser)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $updated = 0;

        // 1. Instant backfill from stored WAN detail snapshots
        $wanSnapshots = OnuDetailSnapshot::where('section', 'wan')
            ->whereHas('onu', fn ($q) => $q->current()->where('tenant_id', $tenantId))
            ->with('onu')
            ->get();
        foreach ($wanSnapshots as $snap) {
            if (! $snap->onu) {
                continue;
            }
            try {
                $payload = $snap->payload;
            } catch (\Throwable) {
                continue;
            }
            $vlans = [];
            foreach ($payload['data']['items'] ?? [] as $item) {
                if (isset($item['vlan_id']) && is_numeric($item['vlan_id'])) {
                    $vlans[] = (int) $item['vlan_id'];
                }
            }
            $vlans = CustomerGroup::normalizeVlans($vlans);
            if ($vlans) {
                $merged = CustomerGroup::normalizeVlans(array_merge(CustomerGroup::onuVlans($snap->onu), $vlans));
                if ($merged !== CustomerGroup::onuVlans($snap->onu) || $snap->onu->vlan_id === null) {
                    $snap->onu->update([
                        'vlan_id' => $snap->onu->vlan_id ?: $vlans[0],
                        'vlan_ids' => $merged,
                    ]);
                    $updated++;
                }
            }
        }

        // 2. Instant backfill from latest stored OLT running-config backup (if any)
        $olts = Olt::where('tenant_id', $tenantId)->where('connection_mode', 'real')->get();
        foreach ($olts as $olt) {
            $backup = OltBackup::where('tenant_id', $tenantId)->where('olt_id', $olt->id)->latest('id')->first();
            if (! $backup || empty($backup->configuration)) {
                continue;
            }
            try {
                $parsed = $parser->onuLabels($backup->configuration);
            } catch (\Throwable) {
                continue;
            }
            if (! $parsed) {
                continue;
            }
            $onus = $olt->onus()->current()->with('port:id,interface_name')->get();
            foreach ($onus as $onu) {
                if (! $onu->port) {
                    continue;
                }
                $key = str_replace('gpon-olt_', '', $onu->port->interface_name).':'.$onu->onu_index;
                $foundVlans = CustomerGroup::normalizeVlans($parsed[$key]['vlan_ids'] ?? []);
                if (! $foundVlans) {
                    continue;
                }
                $merged = CustomerGroup::normalizeVlans(array_merge(CustomerGroup::onuVlans($onu), $foundVlans));
                if ($merged !== CustomerGroup::onuVlans($onu) || $onu->vlan_id === null) {
                    $onu->update([
                        'vlan_id' => $onu->vlan_id ?: $foundVlans[0],
                        'vlan_ids' => $merged,
                    ]);
                    $updated++;
                }
            }
        }

        // 3. Enqueue fresh `names` poll on active real ZTE OLTs to pull live VLANs from show running-config
        $queuedOlts = 0;
        foreach ($olts as $olt) {
            if ($olt->chassis_type === 'C1600') {
                continue;
            }
            try {
                $ops->enqueue($olt, 'names', $request->user(), $request->ip());
                $queuedOlts++;
            } catch (\Throwable) {
                // Skip if OLT already has an active/queued job
            }
        }

        $msg = $updated > 0
            ? "{$updated} ONU berhasil diperbarui VLAN-nya dari cache"
            : 'Sinkronisasi VLAN dijadwalkan';
        if ($queuedOlts > 0) {
            $msg .= " · {$queuedOlts} OLT ZTE sedang membaca VLAN live di background.";
        } else {
            $msg .= '.';
        }

        return response()->json($this->payload($tenantId) + [
            'message' => $msg,
            'updated_from_cache' => $updated,
            'queued_olts' => $queuedOlts,
        ]);
    }

    private function validateInput(Request $request, int $tenantId, ?CustomerGroup $group = null): array
    {
        $rawVlans = $request->input('vlan_ids', $request->input('vlans'));
        $normalized = CustomerGroup::normalizeVlans($rawVlans);
        $request->merge(['vlan_ids' => $normalized]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('customer_groups')->where('tenant_id', $tenantId)->ignore($group?->id)],
            'color_hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'vlan_ids' => ['required', 'array', 'min:1', 'max:50'],
            'vlan_ids.*' => ['integer', 'min:1', 'max:4094'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'vlan_ids.required' => 'Masukkan minimal satu nomor VLAN (1–4094), contoh: 97 atau 95, 99.',
            'vlan_ids.min' => 'Masukkan minimal satu nomor VLAN (1–4094).',
        ]);

        return [
            'name' => trim($data['name']),
            'color_hex' => $data['color_hex'] ?: '#38bdf8',
            'vlan_ids' => CustomerGroup::normalizeVlans($data['vlan_ids']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
        ];
    }

    public function payload(int $tenantId): array
    {
        $groups = CustomerGroup::where('tenant_id', $tenantId)->orderBy('name')->get();
        $threshold = (float) config('nms.bad_rx_threshold', -27);
        $onus = Onu::current()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'phase_state', 'rx_power', 'vlan_id', 'vlan_ids']);

        $vlanCounts = [];
        $withVlan = 0;
        $groupedOnuIds = [];
        $groupStats = [];

        foreach ($groups as $g) {
            $groupStats[$g->id] = [
                'id' => $g->id,
                'name' => $g->name,
                'color_hex' => $g->color_hex,
                'vlan_ids' => CustomerGroup::normalizeVlans($g->vlan_ids ?? []),
                'description' => $g->description,
                'total_onus' => 0,
                'online_onus' => 0,
                'offline_onus' => 0,
                'los_onus' => 0,
                'weak_onus' => 0,
                'percent' => 0,
            ];
        }

        foreach ($onus as $onu) {
            $onuVlans = CustomerGroup::onuVlans($onu);
            if ($onuVlans) {
                $withVlan++;
                foreach ($onuVlans as $v) {
                    $vlanCounts[$v] = ($vlanCounts[$v] ?? 0) + 1;
                }
            }
            $isOnline = $onu->phase_state === 'working';
            $isLos = $onu->phase_state === 'los';
            $isWeak = $onu->rx_power !== null && (float) $onu->rx_power <= $threshold;

            foreach ($groups as $g) {
                $gVlans = $groupStats[$g->id]['vlan_ids'];
                if ($gVlans && $onuVlans && count(array_intersect($gVlans, $onuVlans)) > 0) {
                    $groupStats[$g->id]['total_onus']++;
                    if ($isOnline) {
                        $groupStats[$g->id]['online_onus']++;
                    } else {
                        $groupStats[$g->id]['offline_onus']++;
                    }
                    if ($isLos) {
                        $groupStats[$g->id]['los_onus']++;
                    }
                    if ($isWeak) {
                        $groupStats[$g->id]['weak_onus']++;
                    }
                    $groupedOnuIds[$onu->id] = true;
                }
            }
        }

        foreach ($groupStats as &$st) {
            $st['percent'] = $st['total_onus'] > 0 ? (int) round(($st['online_onus'] / $st['total_onus']) * 100) : 0;
        }
        unset($st);

        ksort($vlanCounts);
        $availableVlans = [];
        foreach ($vlanCounts as $vid => $cnt) {
            $availableVlans[] = ['vlan_id' => $vid, 'count' => $cnt];
        }

        $totalOnus = $onus->count();
        $groupedCount = count($groupedOnuIds);

        return [
            'groups' => array_values($groupStats),
            'summary' => [
                'total_groups' => $groups->count(),
                'total_onus' => $totalOnus,
                'grouped_onus' => $groupedCount,
                'ungrouped_onus' => max(0, $totalOnus - $groupedCount),
                'with_vlan_onus' => $withVlan,
                'without_vlan_onus' => max(0, $totalOnus - $withVlan),
            ],
            'available_vlans' => $availableVlans,
        ];
    }
}
