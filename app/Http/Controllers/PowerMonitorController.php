<?php

namespace App\Http\Controllers;

use App\Models\{NotificationLog, NotificationSetting, Onu, PowerMonitor, PowerMonitorGroup};
use App\Services\{AuditRecorder, PowerMonitorService};
use Illuminate\Http\Request;
use Illuminate\Validation\{Rule, ValidationException};
use Throwable;

class PowerMonitorController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->allows('read'), 403);

        return response()->json($this->payload($request->user()->tenant_id));
    }

    public function storeGroup(Request $request, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $data = $this->validateGroup($request, $tenantId);

        $nextOrder = ((int) PowerMonitorGroup::where('tenant_id', $tenantId)->max('sort_order')) + 1;
        $group = PowerMonitorGroup::create($data + [
            'tenant_id' => $tenantId,
            'sort_order' => $nextOrder,
        ]);

        $audit->record($request, 'power_group.create', $group->name);

        return response()->json([
            'message' => "Kategori monitoring '{$group->name}' berhasil ditambahkan.",
            'group' => $group,
            ...$this->payload($tenantId),
        ], 201);
    }

    public function updateGroup(Request $request, int $id, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $group = PowerMonitorGroup::where('tenant_id', $tenantId)->findOrFail($id);
        $oldName = $group->name;
        $data = $this->validateGroup($request, $tenantId, $group);

        $group->update($data);
        if ($oldName !== $group->name) {
            PowerMonitor::where('tenant_id', $tenantId)
                ->where('power_monitor_group_id', $group->id)
                ->where('group_name', $oldName)
                ->update(['group_name' => $group->name]);
        }

        $audit->record($request, 'power_group.update', $group->name);

        return response()->json([
            'message' => "Kategori monitoring '{$group->name}' diperbarui.",
            'group' => $group->fresh(),
            ...$this->payload($tenantId),
        ]);
    }

    public function destroyGroup(Request $request, int $id, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $group = PowerMonitorGroup::where('tenant_id', $tenantId)->findOrFail($id);
        $name = $group->name;
        $group->delete();

        $audit->record($request, 'power_group.delete', $name);

        return response()->json([
            'message' => "Kategori monitoring '{$name}' dihapus. Target di dalamnya tetap tersimpan di Tanpa Kategori.",
            ...$this->payload($tenantId),
        ]);
    }

    public function store(Request $request, PowerMonitorService $service, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $data = $this->validateMonitor($request, $tenantId);

        $initialStatus = 'unknown';
        if ($data['monitor_type'] === 'onu' && ! empty($data['onu_id'])) {
            $onu = Onu::where('tenant_id', $tenantId)->find($data['onu_id']);
            if ($onu) {
                $initialStatus = $onu->phase_state === 'working' ? 'up' : 'down';
            }
        }

        $monitor = PowerMonitor::create($data + [
            'tenant_id' => $tenantId,
            'status' => $initialStatus,
            'last_checked_at' => $initialStatus !== 'unknown' ? now() : null,
            'last_up_at' => $initialStatus === 'up' ? now() : null,
            'last_down_at' => $initialStatus === 'down' ? now() : null,
        ]);

        if ($monitor->is_enabled && in_array($monitor->monitor_type, ['ping', 'both'], true)) {
            $service->checkOne($monitor);
        }

        $audit->record($request, 'power.create', $monitor->name);

        return response()->json([
            'message' => "Target monitoring '{$monitor->name}' berhasil ditambahkan.",
            ...$this->payload($tenantId),
        ], 201);
    }

    public function update(Request $request, int $id, PowerMonitorService $service, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $monitor = PowerMonitor::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $this->validateMonitor($request, $tenantId);
        $targetChanged = $monitor->ip_address !== $data['ip_address']
            || $monitor->monitor_type !== $data['monitor_type']
            || (int) ($monitor->onu_id ?? 0) !== (int) ($data['onu_id'] ?? 0);

        $monitor->fill($data);
        if ($targetChanged) {
            $monitor->status = 'unknown';
            $monitor->last_latency_ms = null;
            $monitor->packet_loss = null;
            $monitor->consecutive_failures = 0;
            $monitor->last_error = null;
            if ($monitor->monitor_type === 'onu' && $monitor->onu_id) {
                $onu = Onu::where('tenant_id', $tenantId)->find($monitor->onu_id);
                if ($onu) {
                    $monitor->status = $onu->phase_state === 'working' ? 'up' : 'down';
                }
            }
        }
        $monitor->save();

        if ($monitor->is_enabled && in_array($monitor->monitor_type, ['ping', 'both'], true)) {
            $service->checkOne($monitor);
        }

        $audit->record($request, 'power.update', $monitor->name);

        return response()->json([
            'message' => "Target monitoring '{$monitor->name}' berhasil diperbarui.",
            ...$this->payload($tenantId),
        ]);
    }

    public function destroy(Request $request, int $id, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $monitor = PowerMonitor::where('tenant_id', $tenantId)->findOrFail($id);
        $name = $monitor->name;
        $monitor->delete();

        $audit->record($request, 'power.delete', $name);

        return response()->json([
            'message' => "Target monitoring '{$name}' berhasil dihapus.",
            ...$this->payload($tenantId),
        ]);
    }

    public function check(Request $request, PowerMonitorService $service, ?int $id = null)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;

        if ($id !== null) {
            $monitor = PowerMonitor::where('tenant_id', $tenantId)->findOrFail($id);
            if (! in_array($monitor->monitor_type, ['ping', 'both'], true) || ! $monitor->ip_address) {
                return response()->json([
                    'message' => 'Sensor ini menggunakan mode ONU OLT tanpa IP Ping.',
                    ...$this->payload($tenantId),
                ], 422);
            }
            $updated = $service->checkOne($monitor, true);
            $statusLabel = match ($updated->status) {
                'up' => 'UP ('.($updated->last_latency_ms !== null ? $updated->last_latency_ms.' ms' : 'OK').')',
                'warning' => 'Ping RTO · Double Check: ONU masih WORKING (WA tidak dikirim)',
                default => $updated->last_error ?: 'PADAM / Timeout',
            };

            return response()->json([
                'message' => "{$updated->name}: {$statusLabel}",
                ...$this->payload($tenantId),
            ]);
        }

        $checked = $service->checkTenantNow($tenantId);

        return response()->json([
            'message' => 'Pengecekan Ping selesai untuk '.count($checked).' sensor aktif.',
            ...$this->payload($tenantId),
        ]);
    }

    public function settings(Request $request, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $setting = NotificationSetting::forTenant($tenantId);

        $data = $request->validate([
            'power_notify_enabled' => ['required', 'boolean'],
            'power_whatsapp_recipients' => ['nullable', 'string', 'max:1000'],
            'power_telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'power_telegram_thread_id' => ['nullable', 'string', 'max:64'],
            'power_cooldown_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        $setting->update([
            'power_notify_enabled' => (bool) $data['power_notify_enabled'],
            'power_whatsapp_recipients' => trim((string) ($data['power_whatsapp_recipients'] ?? '')) ?: null,
            'power_telegram_chat_id' => trim((string) ($data['power_telegram_chat_id'] ?? '')) ?: null,
            'power_telegram_thread_id' => trim((string) ($data['power_telegram_thread_id'] ?? '')) ?: null,
            'power_cooldown_minutes' => (int) $data['power_cooldown_minutes'],
        ]);

        $audit->record($request, 'power.settings.update', 'Alert Monitoring ONU');

        return response()->json([
            'message' => 'Pengaturan grup alert Monitoring ONU berhasil disimpan.',
            ...$this->payload($tenantId),
        ]);
    }

    public function test(Request $request, PowerMonitorService $service, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'power_whatsapp_recipients' => ['nullable', 'string', 'max:1000'],
            'power_telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'power_telegram_thread_id' => ['nullable', 'string', 'max:64'],
        ]);

        $setting = NotificationSetting::forTenant($tenantId);
        if (array_key_exists('power_whatsapp_recipients', $data)) {
            $setting->power_whatsapp_recipients = trim((string) $data['power_whatsapp_recipients']) ?: null;
        }
        if (array_key_exists('power_telegram_chat_id', $data)) {
            $setting->power_telegram_chat_id = trim((string) $data['power_telegram_chat_id']) ?: null;
        }
        if (array_key_exists('power_telegram_thread_id', $data)) {
            $setting->power_telegram_thread_id = trim((string) $data['power_telegram_thread_id']) ?: null;
        }

        try {
            $results = $service->sendPowerTest($setting);
            $failed = array_values(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'failed'));
            $audit->record($request, 'power.notification.test', 'Tes Alert Monitoring ONU');

            if ($failed) {
                $firstError = $failed[0]['error'] ?? 'Gagal menghubungi gateway.';
                return response()->json([
                    'message' => "Pengiriman tes alert gagal: {$firstError}",
                    ...$this->payload($tenantId),
                ], 422);
            }

            return response()->json([
                'message' => 'Pesan tes alert Monitoring ONU berhasil dikirim ke '.count($results).' tujuan.',
                ...$this->payload($tenantId),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Gagal mengirim tes alert: '.$e->getMessage(),
                ...$this->payload($tenantId),
            ], 422);
        }
    }

    private function validateGroup(Request $request, int $tenantId, ?PowerMonitorGroup $group = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('power_monitor_groups')->where('tenant_id', $tenantId)->ignore($group?->id)],
            'color_hex' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'whatsapp_recipients' => ['nullable', 'string', 'max:500'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'telegram_thread_id' => ['nullable', 'string', 'max:64'],
        ]);

        return [
            'name' => trim($data['name']),
            'color_hex' => $data['color_hex'] ?: '#10b981',
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'whatsapp_recipients' => trim((string) ($data['whatsapp_recipients'] ?? '')) ?: null,
            'telegram_chat_id' => trim((string) ($data['telegram_chat_id'] ?? '')) ?: null,
            'telegram_thread_id' => trim((string) ($data['telegram_thread_id'] ?? '')) ?: null,
        ];
    }

    private function validateMonitor(Request $request, int $tenantId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'power_monitor_group_id' => ['nullable', 'integer'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'monitor_type' => ['required', 'string', Rule::in(['ping', 'onu', 'both'])],
            'ip_address' => ['nullable', 'ip', 'max:45'],
            'onu_id' => ['nullable', 'integer'],
            'onu_sn' => ['nullable', 'string', 'max:64'],
            'whatsapp_recipients_override' => ['nullable', 'string', 'max:500'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $selectedGroup = null;
        if (! empty($data['power_monitor_group_id'])) {
            $selectedGroup = PowerMonitorGroup::where('tenant_id', $tenantId)->find($data['power_monitor_group_id']);
            if (! $selectedGroup) {
                throw ValidationException::withMessages([
                    'power_monitor_group_id' => 'Kategori monitoring tidak ditemukan.',
                ]);
            }
        } elseif (! $request->has('power_monitor_group_id')) {
            $selectedGroup = PowerMonitorGroup::ensureDefaultForTenant($tenantId);
        }

        if (in_array($data['monitor_type'], ['ping', 'both'], true) && empty($data['ip_address'])) {
            throw ValidationException::withMessages([
                'ip_address' => 'Alamat IP wajib diisi untuk mode Ping IP.',
            ]);
        }

        $onuId = $data['onu_id'] ?? null;
        if (! $onuId && ! empty($data['onu_sn'])) {
            $onuId = Onu::current()->where('tenant_id', $tenantId)
                ->where('sn', strtoupper(trim((string) $data['onu_sn'])))
                ->value('id');
            if (! $onuId && in_array($data['monitor_type'], ['onu', 'both'], true)) {
                throw ValidationException::withMessages([
                    'onu_id' => "Serial ONU '{$data['onu_sn']}' tidak ditemukan di OLT tenant ini.",
                ]);
            }
        }
        if (in_array($data['monitor_type'], ['onu', 'both'], true)) {
            if (! $onuId) {
                throw ValidationException::withMessages([
                    'onu_id' => 'Pilih ONU yang dipantau untuk mode ONU OLT.',
                ]);
            }
            $belongs = Onu::current()->where('tenant_id', $tenantId)->whereKey($onuId)->exists();
            if (! $belongs) {
                throw ValidationException::withMessages([
                    'onu_id' => 'ONU tidak ditemukan pada tenant ini.',
                ]);
            }
        } else {
            $onuId = null;
        }

        $groupName = trim((string) ($data['group_name'] ?? ''));
        if ($groupName === '') {
            $groupName = $selectedGroup?->name ?: 'Listrik';
        }

        return [
            'power_monitor_group_id' => $selectedGroup?->id,
            'name' => trim($data['name']),
            'group_name' => $groupName,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'monitor_type' => $data['monitor_type'],
            'ip_address' => in_array($data['monitor_type'], ['ping', 'both'], true) ? trim((string) $data['ip_address']) : null,
            'onu_id' => $onuId,
            'whatsapp_recipients_override' => trim((string) ($data['whatsapp_recipients_override'] ?? '')) ?: null,
            'is_enabled' => (bool) $data['is_enabled'],
        ];
    }

    private function payload(int $tenantId): array
    {
        PowerMonitorGroup::ensureDefaultForTenant($tenantId);

        $monitorGroups = PowerMonitorGroup::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $monitors = PowerMonitor::where('tenant_id', $tenantId)
            ->with([
                'group:id,name,color_hex',
                'onu' => fn ($q) => $q->select('id', 'olt_id', 'olt_port_id', 'onu_index', 'name', 'sn', 'phase_state')
                    ->with(['port:id,interface_name', 'olt:id,name']),
            ])
            ->orderByRaw("CASE WHEN status = 'down' THEN 0 WHEN status = 'warning' THEN 1 WHEN status = 'unknown' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get()
            ->map(function (PowerMonitor $monitor) {
                $row = $monitor->toArray();
                $row['monitor_group_name'] = $monitor->group?->name;
                $row['monitor_group_color'] = $monitor->group?->color_hex;
                if ($monitor->onu) {
                    $ifName = $monitor->onu->port
                        ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $monitor->onu->port->interface_name).':'.$monitor->onu->onu_index
                        : ('#'.$monitor->onu->onu_index);
                    $row['onu_label'] = ($monitor->onu->olt?->name ? $monitor->onu->olt->name.' · ' : '').$monitor->onu->name.' ('.$ifName.')';
                } else {
                    $row['onu_label'] = null;
                }
                return $row;
            })
            ->values();

        $groupCards = $monitorGroups->map(function (PowerMonitorGroup $g) use ($monitors) {
            $items = $monitors->where('power_monitor_group_id', $g->id);
            $active = $items->where('is_enabled', true);
            $total = $items->count();
            $up = $active->where('status', 'up')->count();
            $warning = $active->where('status', 'warning')->count();
            $down = $active->where('status', 'down')->count();
            $unknown = $active->where('status', 'unknown')->count();

            return [
                'id' => $g->id,
                'name' => $g->name,
                'color_hex' => $g->color_hex,
                'description' => $g->description,
                'whatsapp_recipients' => $g->whatsapp_recipients ?? '',
                'telegram_chat_id' => $g->telegram_chat_id ?? '',
                'telegram_thread_id' => $g->telegram_thread_id ?? '',
                'total' => $total,
                'up' => $up,
                'warning' => $warning,
                'down' => $down,
                'unknown' => $unknown,
                'percent' => $total > 0 ? (int) round(($up / $total) * 100) : 0,
            ];
        })->values();

        $ungroupedCount = $monitors->whereNull('power_monitor_group_id')->count();

        $enabled = $monitors->where('is_enabled', true);
        $latencies = $monitors->pluck('last_latency_ms')->filter(fn ($v) => $v !== null);

        $setting = NotificationSetting::forTenant($tenantId);
        $hasAnyWaTarget = ! empty($setting->power_whatsapp_recipients)
            || ! empty($setting->whatsapp_recipients)
            || $monitorGroups->contains(fn ($g) => ! empty($g->whatsapp_recipients))
            || $monitors->contains(fn ($m) => ! empty($m['whatsapp_recipients_override']));
        $hasAnyTgTarget = ! empty($setting->power_telegram_chat_id)
            || ! empty($setting->telegram_chat_id)
            || $monitorGroups->contains(fn ($g) => ! empty($g->telegram_chat_id));
        $hasGateway = (! empty($setting->whatsapp_endpoint_url) && ($setting->whatsapp_enabled || $hasAnyWaTarget))
            || (! empty($setting->telegram_bot_token_enc) && ($setting->telegram_enabled || $hasAnyTgTarget));

        return [
            'monitors' => $monitors,
            'monitor_groups' => $groupCards,
            'ungrouped_count' => $ungroupedCount,
            'groups' => $monitors->pluck('group_name')->filter()->unique()->values(),
            'summary' => [
                'total' => $monitors->count(),
                'up' => $enabled->where('status', 'up')->count(),
                'warning' => $enabled->where('status', 'warning')->count(),
                'down' => $enabled->where('status', 'down')->count(),
                'unknown' => $enabled->where('status', 'unknown')->count(),
                'avg_latency_ms' => $latencies->isNotEmpty() ? round($latencies->avg(), 2) : null,
            ],
            'settings' => [
                'power_notify_enabled' => $setting->power_notify_enabled,
                'power_whatsapp_recipients' => $setting->power_whatsapp_recipients ?? '',
                'power_telegram_chat_id' => $setting->power_telegram_chat_id ?? '',
                'power_telegram_thread_id' => $setting->power_telegram_thread_id ?? '',
                'power_cooldown_minutes' => (int) ($setting->power_cooldown_minutes ?? 5),
                'has_gateway' => $hasGateway,
                'default_whatsapp_recipients' => $setting->whatsapp_recipients ?? '',
                'default_telegram_chat_id' => $setting->telegram_chat_id ?? '',
            ],
            'logs' => NotificationLog::where('tenant_id', $tenantId)
                ->whereIn('event_type', ['power_down', 'power_up', 'power_test'])
                ->latest('id')
                ->limit(25)
                ->get(),
        ];
    }
}
