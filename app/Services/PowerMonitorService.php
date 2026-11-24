<?php

namespace App\Services;

use App\Models\{NotificationLog, NotificationSetting, Olt, OltPort, Onu, PowerMonitor};
use App\Services\Olt\ZteOnuDetailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB, Log};
use Throwable;

class PowerMonitorService
{
    public function __construct(
        private PowerProbeService $probe,
        private NotificationService $notifications,
        private ZteOnuDetailService $detailService
    ) {}

    public function checkAllDue(?int $tenantId = null): int
    {
        $lockKey = 'nms:power-poll:'.($tenantId ?? 'all');
        $lock = Cache::lock($lockKey, 25);
        if (! $lock->get()) {
            return 0;
        }

        $checked = 0;
        try {
            $query = PowerMonitor::query()
                ->where('is_enabled', true)
                ->whereIn('monitor_type', ['ping', 'both'])
                ->whereNotNull('ip_address')
                ->where(function ($q) {
                    $q->whereNull('last_checked_at')
                        ->orWhere('last_checked_at', '<=', now()->subSeconds(25));
                })
                ->orderBy('id');

            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }

            foreach ($query->get() as $monitor) {
                $this->checkOne($monitor);
                $checked++;
            }
        } finally {
            $lock->release();
        }

        return $checked;
    }

    /**
     * Force-check all active Ping monitors for a tenant right now.
     *
     * @return list<PowerMonitor>
     */
    public function checkTenantNow(int $tenantId, bool $notifyOnInitialDown = true): array
    {
        $monitors = PowerMonitor::where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->whereIn('monitor_type', ['ping', 'both'])
            ->whereNotNull('ip_address')
            ->orderBy('id')
            ->get();

        $updated = [];
        foreach ($monitors as $monitor) {
            $updated[] = $this->checkOne($monitor, $notifyOnInitialDown);
        }

        return $updated;
    }

    public function checkOne(PowerMonitor $monitor, bool $notifyOnInitialDown = false): PowerMonitor
    {
        if (! in_array($monitor->monitor_type, ['ping', 'both'], true) || ! $monitor->ip_address) {
            return $monitor;
        }

        $probeResult = $this->probe->ping($monitor->ip_address);
        $onuCheck = null;

        // Double check: if Ping RTO and sensor is linked to an ONU, probe the ONU state live on OLT.
        if (! $probeResult['reachable'] && $monitor->onu_id) {
            $monitor->loadMissing('onu.port', 'onu.olt');
            if ($monitor->onu) {
                $onuCheck = $this->detailService->probeLiveState($monitor->onu);
            }
        }

        $transition = null;
        $previousDownAt = $monitor->last_down_at;
        $alertReason = (string) ($probeResult['error'] ?? 'ICMP Ping Timeout');

        $fresh = DB::transaction(function () use ($monitor, $probeResult, $onuCheck, $notifyOnInitialDown, &$transition, &$previousDownAt, &$alertReason) {
            $locked = PowerMonitor::whereKey($monitor->id)->with('onu.port', 'onu.olt')->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }

            $oldStatus = $locked->status;
            $previousDownAt = $locked->last_down_at;
            $now = now();

            if ($probeResult['reachable']) {
                $updates = [
                    'status' => 'up',
                    'last_latency_ms' => $probeResult['latency_ms'],
                    'packet_loss' => $probeResult['packet_loss'],
                    'consecutive_failures' => 0,
                    'last_checked_at' => $now,
                    'last_up_at' => $now,
                    'last_error' => null,
                ];
                if ($oldStatus !== 'up') {
                    $updates['last_state_changed_at'] = $now;
                }
                $locked->update($updates);

                if ($oldStatus === 'down') {
                    $transition = 'power_up';
                }
            } elseif ($onuCheck !== null) {
                $onuPhase = $onuCheck['phase_state'] ?? null;
                $sourceLabel = ($onuCheck['live'] ?? false) ? 'Live OLT' : 'Snapshot OLT';

                if ($onuPhase === 'working') {
                    // Ping RTO, but ONU is still WORKING on OLT -> skip false alarm WA report.
                    if ($oldStatus === 'down') {
                        $locked->update([
                            'last_latency_ms' => null,
                            'packet_loss' => $probeResult['packet_loss'],
                            'consecutive_failures' => min(65535, $locked->consecutive_failures + 1),
                            'last_checked_at' => $now,
                            'last_error' => "ONU sudah WORKING ({$sourceLabel}), menunggu respon Ping IP…",
                        ]);
                    } else {
                        $updates = [
                            'status' => 'warning',
                            'last_latency_ms' => null,
                            'packet_loss' => $probeResult['packet_loss'],
                            'consecutive_failures' => min(65535, $locked->consecutive_failures + 1),
                            'last_checked_at' => $now,
                            'last_error' => "Ping RTO · Double Check ({$sourceLabel}): ONU masih WORKING (tidak kirim WA)",
                        ];
                        if ($oldStatus !== 'warning') {
                            $updates['last_state_changed_at'] = $now;
                        }
                        $locked->update($updates);
                    }
                } else {
                    $phaseText = $onuPhase ? strtoupper($onuPhase) : 'TIDAK TERJANGKAU';
                    $alertReason = "Ping RTO + Double Check ({$sourceLabel}): Status ONU {$phaseText}";
                    $updates = [
                        'status' => 'down',
                        'last_latency_ms' => null,
                        'packet_loss' => $probeResult['packet_loss'],
                        'consecutive_failures' => min(65535, $locked->consecutive_failures + 1),
                        'last_checked_at' => $now,
                        'last_error' => mb_substr($alertReason, 0, 255),
                    ];
                    if ($oldStatus !== 'down') {
                        $updates['last_down_at'] = $now;
                        $updates['last_state_changed_at'] = $now;
                    }
                    $locked->update($updates);

                    if (in_array($oldStatus, ['up', 'warning'], true) || ($notifyOnInitialDown && $oldStatus === 'unknown')) {
                        $transition = 'power_down';
                    }
                }
            } else {
                $updates = [
                    'status' => 'down',
                    'last_latency_ms' => null,
                    'packet_loss' => $probeResult['packet_loss'],
                    'consecutive_failures' => min(65535, $locked->consecutive_failures + 1),
                    'last_checked_at' => $now,
                    'last_error' => mb_substr((string) ($probeResult['error'] ?? 'ICMP Ping Timeout'), 0, 255),
                ];
                if ($oldStatus !== 'down') {
                    $updates['last_down_at'] = $now;
                    $updates['last_state_changed_at'] = $now;
                }
                $locked->update($updates);

                if (in_array($oldStatus, ['up', 'warning'], true) || ($notifyOnInitialDown && $oldStatus === 'unknown')) {
                    $transition = 'power_down';
                }
            }

            return $locked->fresh(['group', 'onu.port', 'onu.olt']);
        });

        if (! $fresh) {
            return $monitor;
        }

        if ($transition !== null) {
            $this->dispatchMonitorAlert($fresh, $transition, $previousDownAt, $alertReason);
        }

        return $fresh;
    }

    /**
     * Handle ONU status transitions for linked PowerMonitors and power-tagged ONUs.
     *
     * @param list<array{onu:Onu,port?:?OltPort,old_phase?:?string,new_phase:string}> $statusChanges
     */
    public function handleOnuTransition(Olt $olt, array $statusChanges): void
    {
        if (! $statusChanges) {
            return;
        }

        $onuIds = array_values(array_unique(array_map(fn ($item) => $item['onu']->id, $statusChanges)));
        $linkedMonitors = PowerMonitor::where('tenant_id', $olt->tenant_id)
            ->where('is_enabled', true)
            ->whereIn('monitor_type', ['onu', 'both'])
            ->whereIn('onu_id', $onuIds)
            ->with('group', 'onu.port', 'onu.olt')
            ->get()
            ->groupBy('onu_id');

        $downStates = ['dying-gasp', 'poweroff', 'offline', 'los', 'rebooting', 'restorefail', 'sync-mib', 'logging', 'auth'];
        $recoveryFromStates = ['dying-gasp', 'poweroff', 'offline', 'los', 'rebooting', 'restorefail', 'sync-mib', 'logging', 'auth'];

        foreach ($statusChanges as $item) {
            /** @var Onu $onu */
            $onu = $item['onu'];
            $oldPhase = (string) ($item['old_phase'] ?? '');
            $newPhase = (string) ($item['new_phase'] ?? '');
            $port = $item['port'] ?? $onu->port;

            $isDown = in_array($newPhase, $downStates, true);
            $isUp = $newPhase === 'working' && in_array($oldPhase, $recoveryFromStates, true);
            if (! $isDown && ! $isUp) {
                continue;
            }

            $monitors = $linkedMonitors->get($onu->id);
            if ($monitors && $monitors->isNotEmpty()) {
                foreach ($monitors as $monitor) {
                    if ($monitor->monitor_type === 'both' && $monitor->ip_address) {
                        $this->checkOne($monitor, true);
                        continue;
                    }

                    if ($isDown) {
                        $prevDownAt = $monitor->last_down_at;
                        $transition = null;
                        $fresh = DB::transaction(function () use ($monitor, $newPhase, &$transition) {
                            $locked = PowerMonitor::whereKey($monitor->id)->with('onu.port', 'onu.olt')->lockForUpdate()->first();
                            if (! $locked) {
                                return null;
                            }
                            $oldStatus = $locked->status;
                            $now = now();
                            $locked->update([
                                'status' => 'down',
                                'last_checked_at' => $now,
                                'last_down_at' => $oldStatus !== 'down' ? $now : $locked->last_down_at,
                                'last_state_changed_at' => $oldStatus !== 'down' ? $now : $locked->last_state_changed_at,
                                'last_error' => 'ONU '.strtoupper($newPhase),
                            ]);
                            if ($oldStatus !== 'down') {
                                $transition = 'power_down';
                            }

                            return $locked->fresh(['group', 'onu.port', 'onu.olt']);
                        });

                        if ($fresh && $transition === 'power_down') {
                            $this->dispatchMonitorAlert($fresh, 'power_down', $prevDownAt, 'ONU '.strtoupper($newPhase), $olt->id, $onu->id);
                        }
                    } elseif ($isUp) {
                        $prevDownAt = $monitor->last_down_at;
                        $transition = null;
                        $fresh = DB::transaction(function () use ($monitor, &$transition) {
                            $locked = PowerMonitor::whereKey($monitor->id)->with('onu.port', 'onu.olt')->lockForUpdate()->first();
                            if (! $locked) {
                                return null;
                            }
                            $oldStatus = $locked->status;
                            $now = now();
                            $locked->update([
                                'status' => 'up',
                                'last_checked_at' => $now,
                                'last_up_at' => $now,
                                'last_state_changed_at' => $oldStatus !== 'up' ? $now : $locked->last_state_changed_at,
                                'last_error' => null,
                            ]);
                            if ($oldStatus === 'down') {
                                $transition = 'power_up';
                            }

                            return $locked->fresh(['group', 'onu.port', 'onu.olt']);
                        });

                        if ($fresh && $transition === 'power_up') {
                            $this->dispatchMonitorAlert($fresh, 'power_up', $prevDownAt, 'ONU Kembali Working', $olt->id, $onu->id);
                        }
                    }
                }
                continue;
            }

            // Fallback: ONUs tagged with "Monitoring Listrik" / "PLN" / "Power" also trigger dedicated power alerts.
            $onu->loadMissing('tags');
            $hasPowerTag = $onu->tags->contains(fn ($tag) => (bool) preg_match('/\b(?:listrik|pln|power)\b/i', (string) $tag->tag_name));
            if ($hasPowerTag) {
                $this->dispatchPowerTagAlert($olt, $onu, $port, $oldPhase, $newPhase, $isDown ? 'power_down' : 'power_up');
            }
        }
    }

    public function sendPowerTest(NotificationSetting $setting): array
    {
        $sample = PowerMonitor::where('tenant_id', $setting->tenant_id)->with(['group', 'onu.port', 'onu.olt'])->orderBy('id')->first();
        $name = $sample?->name ?? 'MONITORING LISTRIK POP-01';
        $group = $sample?->group?->name ?? $sample?->group_name ?? 'Listrik';
        $target = $sample?->ip_address ?? '10.10.99.10';

        $customTemplate = trim((string) ($setting->power_down_template ?: $sample?->group?->down_message_template ?: ''));
        if ($customTemplate !== '') {
            $port = $sample?->onu?->port;
            $ifName = $port
                ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $port->interface_name).':'.$sample->onu->onu_index
                : 'gpon-onu_1/2/1:3';
            $message = $this->renderCustomTemplate($customTemplate, [
                'nama' => $name,
                'perangkat' => $name,
                'kategori' => $group,
                'KATEGORI' => mb_strtoupper($group),
                'group' => $group,
                'area' => $sample?->location ?: 'POP Utama',
                'lokasi' => $sample?->location ?: $name,
                'ip' => $target,
                'target' => $target,
                'onu' => $sample?->onu?->name ?: 'ONU-SAMPLE',
                'sn' => $sample?->onu?->sn ?: 'ZTEGC0FFEE01',
                'olt' => $sample?->onu?->olt?->name ?: 'OLT-PUSAT',
                'port' => $ifName,
                'state' => 'DYING-GASP',
                'status' => 'PADAM / OFFLINE (Simulasi Tes)',
                'alasan' => 'Ping RTO + Status ONU DYING-GASP (Simulasi Tes)',
                'hasil_cek' => 'Ping RTO + Status ONU DYING-GASP (Simulasi Tes)',
                'latency' => '—',
                'durasi' => '—',
                'waktu' => now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB',
            ]);
        } else {
            $message = implode("\n", [
                '⚡ *[TES MONITORING LISTRIK] PLN / POWER ALERT*',
                '',
                "• Perangkat: *{$name}*",
                "• Group: {$group}",
                "• Target IP / ONU: `{$target}`",
                '• Status: UP ➔ *PADAM (Simulasi Tes)*',
                '• Double Check: Ping RTO + Status ONU DYING-GASP',
                '• Waktu: '.now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB',
                '',
                '_Integrasi alert Monitoring Listrik LUMINA NMS aktif dan siap digunakan._',
            ]);
        }

        return $this->notifications->deliverPowerMessage($setting, $message, 'power_test', $sample?->whatsapp_recipients_override, null, null, $sample?->id, true);
    }

    private function dispatchMonitorAlert(PowerMonitor $monitor, string $eventType, ?Carbon $previousDownAt, string $reason, ?int $oltId = null, ?int $onuId = null): void
    {
        try {
            $setting = NotificationSetting::where('tenant_id', $monitor->tenant_id)->first();
            if (! $setting || ! $setting->power_notify_enabled) {
                return;
            }

            if ($eventType === 'power_down' && $this->isPowerInCooldown($monitor->tenant_id, $monitor->id, (int) ($setting->power_cooldown_minutes ?? 5))) {
                return;
            }

            $durationText = null;
            if ($eventType === 'power_up' && $previousDownAt) {
                $durationText = $this->formatDuration($previousDownAt, now());
            }

            $monitor->loadMissing('group');
            $message = $this->formatPowerMonitorMessage($monitor, $eventType, $reason, $durationText, $setting);
            $oltId ??= $monitor->onu?->olt_id;
            $onuId ??= $monitor->onu_id;

            $waRecipient = trim((string) ($monitor->whatsapp_recipients_override ?? ''))
                ?: trim((string) ($monitor->group?->whatsapp_recipients ?? ''))
                ?: null;
            $tgRecipient = trim((string) ($monitor->group?->telegram_chat_id ?? '')) ?: null;
            $tgThread = trim((string) ($monitor->group?->telegram_thread_id ?? '')) ?: null;

            $this->notifications->deliverPowerMessage(
                $setting,
                $message,
                $eventType,
                $waRecipient,
                $oltId,
                $onuId,
                $monitor->id,
                false,
                $tgRecipient,
                $tgThread
            );
        } catch (Throwable $e) {
            Log::warning('Power monitor notification failed', [
                'power_monitor_id' => $monitor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchPowerTagAlert(Olt $olt, Onu $onu, ?OltPort $port, string $oldPhase, string $newPhase, string $eventType): void
    {
        try {
            $setting = NotificationSetting::where('tenant_id', $olt->tenant_id)->first();
            if (! $setting || ! $setting->power_notify_enabled) {
                return;
            }

            $ifName = $port ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $port->interface_name).':'.$onu->onu_index : ('ONU #'.$onu->onu_index);
            $customer = ($onu->name && $onu->name !== $onu->sn) ? $onu->name : $onu->sn;
            $nowWib = now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB';

            $customTemplate = $eventType === 'power_down'
                ? trim((string) ($setting->power_down_template ?? ''))
                : trim((string) ($setting->power_up_template ?? ''));

            if ($customTemplate !== '') {
                $message = $this->renderCustomTemplate($customTemplate, [
                    'nama' => $customer,
                    'perangkat' => $customer,
                    'kategori' => 'Listrik',
                    'KATEGORI' => 'LISTRIK',
                    'group' => 'Listrik',
                    'area' => '',
                    'lokasi' => $customer,
                    'ip' => '',
                    'target' => $customer,
                    'onu' => $customer,
                    'sn' => (string) $onu->sn,
                    'olt' => (string) $olt->name,
                    'port' => $ifName,
                    'state' => strtoupper($newPhase),
                    'status' => strtoupper($oldPhase).' ➔ '.strtoupper($newPhase),
                    'alasan' => 'ONU '.strtoupper($newPhase),
                    'hasil_cek' => 'ONU '.strtoupper($newPhase),
                    'latency' => '',
                    'durasi' => '',
                    'waktu' => $nowWib,
                ]);
            } else {
                $header = $eventType === 'power_down'
                    ? '⚡ *[MONITORING LISTRIK] PLN / LISTRIK PADAM*'
                    : '✅ *[MONITORING LISTRIK] LISTRIK MENYALA KEMBALI*';

                $lines = [
                    $header,
                    '',
                    "• Sensor ONU: *{$customer}*",
                    "• OLT / Port: {$olt->name} (`{$ifName}`)",
                    "• Serial (SN): `{$onu->sn}`",
                    '• Status: '.strtoupper($oldPhase).' ➔ *'.strtoupper($newPhase).'*',
                    "• Waktu: {$nowWib}",
                ];
                $message = implode("\n", $lines);
            }

            $this->notifications->deliverPowerMessage($setting, $message, $eventType, null, $olt->id, $onu->id, null);
        } catch (Throwable $e) {
            Log::warning('Power tag ONU notification failed', [
                'onu_id' => $onu->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatPowerMonitorMessage(PowerMonitor $monitor, string $eventType, string $reason, ?string $durationText, ?NotificationSetting $setting = null): string
    {
        $categoryName = trim((string) ($monitor->group?->name ?: $monitor->group_name ?: 'Listrik'));
        $isPowerCategory = (bool) preg_match('/listrik|pln|power/i', $categoryName);
        $upperCategory = mb_strtoupper($categoryName);
        $nowWib = now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB';

        $port = $monitor->onu?->port;
        $ifName = $monitor->onu
            ? ($port ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $port->interface_name).':'.$monitor->onu->onu_index : ('ONU #'.$monitor->onu->onu_index))
            : '';
        $oltName = $monitor->onu?->olt?->name ?? '';
        $onuStateLabel = $monitor->onu ? strtoupper((string) ($monitor->onu->phase_state ?? 'unknown')) : '';
        $latencyText = $monitor->last_latency_ms !== null ? number_format((float) $monitor->last_latency_ms, 2).' ms' : '';

        $statusLabel = $eventType === 'power_down'
            ? ($isPowerCategory ? 'PADAM / OFFLINE' : 'OFFLINE / DOWN')
            : (($isPowerCategory ? 'MENYALA KEMBALI (UP)' : 'ONLINE KEMBALI (UP)').($latencyText !== '' ? " (Ping: {$latencyText})" : ''));

        $customTemplate = $eventType === 'power_down'
            ? trim((string) ($monitor->group?->down_message_template ?: $setting?->power_down_template ?: ''))
            : trim((string) ($monitor->group?->up_message_template ?: $setting?->power_up_template ?: ''));

        if ($customTemplate !== '') {
            return $this->renderCustomTemplate($customTemplate, [
                'nama' => (string) $monitor->name,
                'perangkat' => (string) $monitor->name,
                'kategori' => $categoryName,
                'KATEGORI' => $upperCategory,
                'group' => $categoryName,
                'area' => (string) ($monitor->location ?? ''),
                'lokasi' => (string) ($monitor->location ?: $monitor->name),
                'ip' => (string) ($monitor->ip_address ?? ''),
                'target' => (string) ($monitor->ip_address ?: ($monitor->onu?->name ?? $monitor->name)),
                'onu' => (string) ($monitor->onu?->name ?? ''),
                'sn' => (string) ($monitor->onu?->sn ?? ''),
                'olt' => $oltName,
                'port' => $ifName,
                'state' => $onuStateLabel,
                'status' => $statusLabel,
                'alasan' => $reason,
                'hasil_cek' => $reason,
                'latency' => $latencyText,
                'durasi' => (string) ($durationText ?? ''),
                'waktu' => $nowWib,
            ]);
        }

        if ($isPowerCategory) {
            $header = $eventType === 'power_down'
                ? '⚡ *[MONITORING LISTRIK] PLN / LISTRIK PADAM*'
                : '✅ *[MONITORING LISTRIK] LISTRIK MENYALA KEMBALI*';
        } else {
            $header = $eventType === 'power_down'
                ? "🚨 *[MONITORING {$upperCategory}] PERANGKAT OFFLINE / DOWN*"
                : "✅ *[MONITORING {$upperCategory}] PERANGKAT ONLINE KEMBALI*";
        }

        $lines = [
            $header,
            '',
            "• Lokasi / Perangkat: *{$monitor->name}*",
            "• Kategori: {$categoryName}",
        ];

        if ($monitor->location) {
            $lines[] = "• Area: {$monitor->location}";
        }
        if ($monitor->ip_address) {
            $lines[] = "• Target IP: `{$monitor->ip_address}`";
        }
        if ($monitor->onu) {
            $lines[] = "• Sensor ONU: {$monitor->onu->name} (`{$monitor->onu->sn}`)";
            $lines[] = "• OLT / Port: ".($oltName ?: 'OLT')." (`{$ifName}`) · State: *{$onuStateLabel}*";
        }

        if ($eventType === 'power_down') {
            $lines[] = $isPowerCategory ? '• Status: *PADAM / OFFLINE*' : '• Status: *OFFLINE / DOWN*';
            $lines[] = "• Hasil Cek: {$reason}";
        } else {
            $latency = $latencyText !== '' ? " (Ping: {$latencyText})" : '';
            $lines[] = ($isPowerCategory ? '• Status: *MENYALA KEMBALI (UP)*' : '• Status: *ONLINE KEMBALI (UP)*').$latency;
            if ($durationText) {
                $lines[] = ($isPowerCategory ? '• Durasi Padam: ' : '• Durasi Gangguan: ').$durationText;
            }
        }

        $lines[] = "• Waktu: {$nowWib}";

        return implode("\n", $lines);
    }

    /**
     * Render a user-defined message template with {placeholder} tokens.
     *
     * @param array<string,string> $vars
     */
    public function renderCustomTemplate(string $template, array $vars): string
    {
        $optionalKeys = ['area', 'ip', 'onu', 'sn', 'olt', 'port', 'state', 'latency', 'durasi'];
        $rawLines = preg_split('/\r\n|\r|\n/', $template) ?: [];
        $outLines = [];

        foreach ($rawLines as $line) {
            if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $line, $matches)) {
                $tokens = $matches[1];
                $allOptionalAndEmpty = true;
                foreach ($tokens as $tk) {
                    $val = array_key_exists($tk, $vars) ? trim((string) $vars[$tk]) : null;
                    if ($val === null || $val !== '' || ! in_array($tk, $optionalKeys, true)) {
                        $allOptionalAndEmpty = false;
                        break;
                    }
                }
                if ($allOptionalAndEmpty) {
                    continue;
                }
            }

            $rendered = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function (array $m) use ($vars) {
                $key = $m[1];
                if (! array_key_exists($key, $vars)) {
                    return $m[0];
                }
                $val = trim((string) $vars[$key]);
                return $val !== '' ? $val : '—';
            }, $line);

            $outLines[] = $rendered;
        }

        return trim(implode("\n", $outLines));
    }

    private function formatDuration(Carbon $from, Carbon $to): string
    {
        $seconds = max(1, (int) $from->diffInSeconds($to));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remSec = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours.' jam';
        }
        if ($minutes > 0) {
            $parts[] = $minutes.' menit';
        }
        if ($hours === 0 && $remSec > 0) {
            $parts[] = $remSec.' detik';
        }

        return implode(' ', $parts);
    }

    private function isPowerInCooldown(int $tenantId, int $monitorId, int $cooldownMinutes): bool
    {
        if ($cooldownMinutes <= 0) {
            return false;
        }

        $latest = NotificationLog::where('tenant_id', $tenantId)
            ->where('power_monitor_id', $monitorId)
            ->where('event_type', 'power_down')
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subMinutes($cooldownMinutes))
            ->latest('id')
            ->first();

        if (! $latest) {
            return false;
        }

        $recoveredAfter = NotificationLog::where('tenant_id', $tenantId)
            ->where('power_monitor_id', $monitorId)
            ->where('event_type', 'power_up')
            ->where('status', 'sent')
            ->where('id', '>', $latest->id)
            ->exists();

        return ! $recoveredAfter;
    }
}
