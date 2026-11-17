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
        $lock = Cache::lock($lockKey, 55);
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
                        ->orWhere('last_checked_at', '<=', now()->subSeconds(55));
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

            return $locked->fresh(['onu.port', 'onu.olt']);
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
            ->with('onu.port', 'onu.olt')
            ->get()
            ->groupBy('onu_id');

        $downStates = ['dying-gasp', 'poweroff', 'offline', 'los', 'restorefail', 'sync-mib', 'logging', 'auth'];
        $recoveryFromStates = ['dying-gasp', 'poweroff', 'offline', 'los', 'restorefail', 'sync-mib', 'logging', 'auth'];

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

                            return $locked->fresh(['onu.port', 'onu.olt']);
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

                            return $locked->fresh(['onu.port', 'onu.olt']);
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
        $sample = PowerMonitor::where('tenant_id', $setting->tenant_id)->orderBy('id')->first();
        $name = $sample?->name ?? 'MONITORING LISTRIK POP-01';
        $group = $sample?->group_name ?? 'MONITORING-LISTRIK';
        $target = $sample?->ip_address ?? '10.10.99.10';

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

            $message = $this->formatPowerMonitorMessage($monitor, $eventType, $reason, $durationText);
            $oltId ??= $monitor->onu?->olt_id;
            $onuId ??= $monitor->onu_id;

            $this->notifications->deliverPowerMessage(
                $setting,
                $message,
                $eventType,
                $monitor->whatsapp_recipients_override,
                $oltId,
                $onuId,
                $monitor->id
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
                '• Waktu: '.now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB',
            ];

            $this->notifications->deliverPowerMessage($setting, implode("\n", $lines), $eventType, null, $olt->id, $onu->id, null);
        } catch (Throwable $e) {
            Log::warning('Power tag ONU notification failed', [
                'onu_id' => $onu->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatPowerMonitorMessage(PowerMonitor $monitor, string $eventType, string $reason, ?string $durationText): string
    {
        $header = $eventType === 'power_down'
            ? '⚡ *[MONITORING LISTRIK] PLN / LISTRIK PADAM*'
            : '✅ *[MONITORING LISTRIK] LISTRIK MENYALA KEMBALI*';

        $lines = [
            $header,
            '',
            "• Lokasi / Perangkat: *{$monitor->name}*",
            "• Group: {$monitor->group_name}",
        ];

        if ($monitor->location) {
            $lines[] = "• Area: {$monitor->location}";
        }
        if ($monitor->ip_address) {
            $lines[] = "• Target IP: `{$monitor->ip_address}`";
        }
        if ($monitor->onu) {
            $port = $monitor->onu->port;
            $ifName = $port ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $port->interface_name).':'.$monitor->onu->onu_index : ('ONU #'.$monitor->onu->onu_index);
            $oltName = $monitor->onu->olt?->name ?? 'OLT';
            $onuStateLabel = strtoupper((string) ($monitor->onu->phase_state ?? 'unknown'));
            $lines[] = "• Sensor ONU: {$monitor->onu->name} (`{$monitor->onu->sn}`)";
            $lines[] = "• OLT / Port: {$oltName} (`{$ifName}`) · State: *{$onuStateLabel}*";
        }

        if ($eventType === 'power_down') {
            $lines[] = '• Status: *PADAM / OFFLINE*';
            $lines[] = "• Hasil Cek: {$reason}";
        } else {
            $latency = $monitor->last_latency_ms !== null ? ' (Ping: '.number_format((float) $monitor->last_latency_ms, 2).' ms)' : '';
            $lines[] = "• Status: *MENYALA KEMBALI (UP)*{$latency}";
            if ($durationText) {
                $lines[] = "• Durasi Padam: {$durationText}";
            }
        }

        $lines[] = '• Waktu: '.now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB';

        return implode("\n", $lines);
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
