<?php

namespace App\Services;

use App\Models\{FtthAssignment, FtthNode, NotificationLog, NotificationSetting, Olt, OltPort, Onu};
use Illuminate\Support\Facades\Http;
use Throwable;

class NotificationService
{
    public function dispatchBatch(Olt $olt, array $statusChanges, array $opticalChanges = []): void
    {
        $setting = NotificationSetting::where('tenant_id', $olt->tenant_id)->first();
        if (! $setting || (! $setting->whatsapp_enabled && ! $setting->telegram_enabled)) {
            return;
        }

        $events = [];
        foreach ($statusChanges as $item) {
            /** @var Onu $onu */
            $onu = $item['onu'];
            $oldPhase = (string) ($item['old_phase'] ?? '');
            $newPhase = (string) ($item['new_phase'] ?? '');

            if ($newPhase === 'los' && $setting->notify_on_los) {
                $events[] = ['type' => 'onu_los', 'onu' => $onu, 'port' => $item['port'] ?? null, 'old_phase' => $oldPhase, 'new_phase' => $newPhase];
            } elseif (in_array($newPhase, ['dying-gasp', 'poweroff', 'offline'], true) && $setting->notify_on_dying_gasp) {
                $events[] = ['type' => 'onu_dying_gasp', 'onu' => $onu, 'port' => $item['port'] ?? null, 'old_phase' => $oldPhase, 'new_phase' => $newPhase];
            } elseif ($newPhase === 'working' && in_array($oldPhase, ['los', 'dying-gasp', 'poweroff', 'offline'], true) && $setting->notify_on_recovery) {
                $events[] = ['type' => 'onu_recovery', 'onu' => $onu, 'port' => $item['port'] ?? null, 'old_phase' => $oldPhase, 'new_phase' => $newPhase];
            }
        }

        $threshold = (float) ($setting->bad_rx_threshold ?? config('nms.bad_rx_threshold', -27.00));
        if ($setting->notify_on_bad_rx) {
            foreach ($opticalChanges as $item) {
                /** @var Onu $onu */
                $onu = $item['onu'];
                $oldRx = $item['old_rx'];
                $newRx = $item['new_rx'];
                if ($onu->phase_state === 'working' && $newRx !== null && $newRx <= $threshold && ($oldRx === null || $oldRx > $threshold)) {
                    $events[] = ['type' => 'onu_bad_rx', 'onu' => $onu, 'port' => $item['port'] ?? null, 'old_rx' => $oldRx, 'new_rx' => $newRx, 'threshold' => $threshold];
                }
            }
        }

        if (! $events) {
            return;
        }

        $filtered = array_values(array_filter($events, fn (array $ev) => ! $this->isInCooldown($olt->tenant_id, $ev['onu']->id, $ev['type'], $setting->cooldown_minutes)));
        if (! $filtered) {
            return;
        }

        if (count($filtered) > 5) {
            $message = $this->formatBatchSummary($olt, $filtered);
            $this->deliver($setting, $message, $olt->id, null, $filtered[0]['type']);
            return;
        }

        foreach ($filtered as $ev) {
            $message = $this->formatOnuMessage($olt, $ev['onu'], $ev['port'], $ev['type'], $ev);
            $this->deliver($setting, $message, $olt->id, $ev['onu']->id, $ev['type']);
        }
    }

    public function sendTest(NotificationSetting $setting, string $channel, ?Olt $olt = null, ?Onu $onu = null): array
    {
        $olt ??= Olt::where('tenant_id', $setting->tenant_id)->first();
        $onu ??= $olt ? Onu::where('olt_id', $olt->id)->with('port')->first() : null;

        $message = $this->formatTestMessage($setting, $olt, $onu);

        if ($channel === 'whatsapp') {
            return $this->sendWhatsApp($setting, $message, $olt?->id, $onu?->id, 'test');
        }

        return $this->sendTelegram($setting, $message, $olt?->id, $onu?->id, 'test');
    }

    public function deliver(NotificationSetting $setting, string $message, ?int $oltId, ?int $onuId, string $eventType): void
    {
        if ($setting->whatsapp_enabled) {
            try {
                $this->sendWhatsApp($setting, $message, $oltId, $onuId, $eventType);
            } catch (Throwable) {
            }
        }

        if ($setting->telegram_enabled) {
            try {
                $this->sendTelegram($setting, $message, $oltId, $onuId, $eventType);
            } catch (Throwable) {
            }
        }
    }

    public function sendWhatsApp(NotificationSetting $setting, string $message, ?int $oltId = null, ?int $onuId = null, string $eventType = 'test'): array
    {
        $recipients = $this->parseRecipients((string) $setting->whatsapp_recipients);
        if (! $recipients) {
            throw new \RuntimeException('Nomor tujuan WhatsApp atau Group JID belum diisi.');
        }

        $provider = $setting->whatsapp_provider ?: 'baileys';
        $endpoint = trim((string) $setting->whatsapp_endpoint_url);
        if ($provider === 'fonnte' && $endpoint === '') {
            $endpoint = 'https://api.fonnte.com/send';
        } elseif ($endpoint === '') {
            $endpoint = 'http://127.0.0.1:3001/send-message';
        }

        $apiKey = trim((string) ($setting->whatsapp_api_key_enc ?? ''));
        $sessionId = trim((string) ($setting->whatsapp_session_id ?: 'default'));
        $results = [];

        foreach ($recipients as $rawTarget) {
            [$number, $jid] = $this->normalizeWhatsAppTarget($rawTarget);
            try {
                $headers = ['Accept' => 'application/json'];
                if ($apiKey !== '') {
                    if (in_array($provider, ['fonnte', 'wablas'], true)) {
                        $headers['Authorization'] = $apiKey;
                    } else {
                        $headers['Authorization'] = 'Bearer '.$apiKey;
                        $headers['x-api-key'] = $apiKey;
                        $headers['apikey'] = $apiKey;
                    }
                }

                $payload = match ($provider) {
                    'fonnte' => [
                        'target' => $number,
                        'message' => $message,
                        'countryCode' => '62',
                    ],
                    'wablas' => [
                        'phone' => $number,
                        'message' => $message,
                    ],
                    default => [
                        'number' => str_contains($rawTarget, '@') ? $rawTarget : $number,
                        'phone' => str_contains($rawTarget, '@') ? $rawTarget : $number,
                        'to' => str_contains($rawTarget, '@') ? $rawTarget : $number,
                        'target' => str_contains($rawTarget, '@') ? $rawTarget : $number,
                        'jid' => $jid,
                        'chatId' => $jid,
                        'message' => $message,
                        'text' => $message,
                        'body' => $message,
                        'session' => $sessionId,
                        'sessionId' => $sessionId,
                    ],
                };

                $response = Http::timeout(10)->withHeaders($headers)->post($endpoint, $payload);
                $body = $response->json();
                $logicalFail = is_array($body) && array_key_exists('status', $body) && $body['status'] === false;

                if (! $response->successful() || $logicalFail) {
                    $err = is_array($body) ? ($body['reason'] ?? $body['message'] ?? $body['error'] ?? ('HTTP '.$response->status())) : ('HTTP '.$response->status());
                    throw new \RuntimeException((string) $err);
                }

                $this->log($setting->tenant_id, $oltId, $onuId, 'whatsapp', $eventType, $rawTarget, 'sent', $message);
                $results[] = ['recipient' => $rawTarget, 'status' => 'sent'];
            } catch (Throwable $e) {
                $this->log($setting->tenant_id, $oltId, $onuId, 'whatsapp', $eventType, $rawTarget, 'failed', $message, $e->getMessage());
                $results[] = ['recipient' => $rawTarget, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function sendTelegram(NotificationSetting $setting, string $message, ?int $oltId = null, ?int $onuId = null, string $eventType = 'test'): array
    {
        $token = trim((string) ($setting->telegram_bot_token_enc ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Bot Token Telegram belum disimpan.');
        }

        $chatIds = $this->parseRecipients((string) $setting->telegram_chat_id);
        if (! $chatIds) {
            throw new \RuntimeException('Chat ID Telegram belum diisi.');
        }

        $threadId = trim((string) ($setting->telegram_thread_id ?? ''));
        $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
        $results = [];

        foreach ($chatIds as $chatId) {
            try {
                $payload = [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'disable_web_page_preview' => true,
                ];
                if ($threadId !== '' && ctype_digit($threadId)) {
                    $payload['message_thread_id'] = (int) $threadId;
                }

                $response = Http::timeout(10)->post($endpoint, $payload);
                $body = $response->json();
                if (! $response->successful() || (is_array($body) && ($body['ok'] ?? true) === false)) {
                    $err = is_array($body) ? ($body['description'] ?? ('HTTP '.$response->status())) : ('HTTP '.$response->status());
                    throw new \RuntimeException((string) $err);
                }

                $this->log($setting->tenant_id, $oltId, $onuId, 'telegram', $eventType, $chatId, 'sent', $message);
                $results[] = ['recipient' => $chatId, 'status' => 'sent'];
            } catch (Throwable $e) {
                $this->log($setting->tenant_id, $oltId, $onuId, 'telegram', $eventType, $chatId, 'failed', $message, $e->getMessage());
                $results[] = ['recipient' => $chatId, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function formatOnuMessage(Olt $olt, Onu $onu, ?OltPort $port, string $eventType, array $meta = []): string
    {
        $port ??= $onu->port;
        $ifName = $port ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $port->interface_name).':'.$onu->onu_index : ('ONU #'.$onu->onu_index);
        $customer = ($onu->name && $onu->name !== $onu->sn) ? $onu->name : 'Pelanggan ('.$onu->sn.')';

        $header = match ($eventType) {
            'onu_los' => '🚨 *[NMS ALERT] ONU LOSS (PUTUS OPTIK)*',
            'onu_dying_gasp' => '⚠️ *[NMS ALERT] ONU MATI LISTRIK / OFFLINE*',
            'onu_bad_rx' => '📉 *[NMS ALERT] REDAMAN OPTIK BURUK*',
            'onu_recovery' => '✅ *[NMS RECOVERY] ONU KEMBALI ONLINE*',
            default => '🔔 *[NMS NOTIFIKASI ONU]*',
        };

        $lines = [
            $header,
            '',
            "• Pelanggan: *{$customer}*",
            "• Serial (SN): `{$onu->sn}`",
            "• OLT / Port: {$olt->name} (`{$ifName}`)",
        ];

        if ($eventType === 'onu_bad_rx') {
            $oldRx = isset($meta['old_rx']) && $meta['old_rx'] !== null ? number_format((float) $meta['old_rx'], 2).' dBm' : '—';
            $newRx = number_format((float) ($meta['new_rx'] ?? $onu->rx_power), 2).' dBm';
            $limit = number_format((float) ($meta['threshold'] ?? -27.00), 2).' dBm';
            $lines[] = "• Redaman ONT: {$oldRx} ➔ *{$newRx}* (Batas: {$limit})";
        } else {
            $oldLabel = strtoupper((string) ($meta['old_phase'] ?? 'working'));
            $newLabel = strtoupper((string) ($meta['new_phase'] ?? $onu->phase_state));
            $lines[] = "• Status: {$oldLabel} ➔ *{$newLabel}*";
            if ($onu->rx_power !== null) {
                $lines[] = '• Redaman Terakhir: '.number_format((float) $onu->rx_power, 2).' dBm';
            }
        }

        if ($onu->pppoe_user) {
            $lines[] = "• PPPoE: `{$onu->pppoe_user}`";
        }
        if ($onu->description) {
            $lines[] = "• Alamat/Ket: {$onu->description}";
        }

        $assignment = FtthAssignment::where('onu_id', $onu->id)->first();
        $odp = $assignment ? FtthNode::find($assignment->node_id) : null;
        if ($odp && $assignment) {
            $lines[] = "• ODP: {$odp->name} (Port {$assignment->port_number})";
        }

        $lat = $onu->latitude ?? $odp?->latitude;
        $lng = $onu->longitude ?? $odp?->longitude;
        if ($lat !== null && $lng !== null) {
            $lines[] = "• Lokasi Maps: https://maps.google.com/?q={$lat},{$lng}";
        }

        $lines[] = '• Waktu: '.now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB';

        return implode("\n", $lines);
    }

    private function formatBatchSummary(Olt $olt, array $events): string
    {
        $losCount = count(array_filter($events, fn ($e) => $e['type'] === 'onu_los'));
        $dgCount = count(array_filter($events, fn ($e) => $e['type'] === 'onu_dying_gasp'));
        $recCount = count(array_filter($events, fn ($e) => $e['type'] === 'onu_recovery'));
        $rxCount = count(array_filter($events, fn ($e) => $e['type'] === 'onu_bad_rx'));

        $lines = [
            '🚨 *[NMS ALERT MASSAL] PERUBAHAN STATUS ONU*',
            '',
            "• OLT: *{$olt->name}* ({$olt->ip_address})",
            '• Ringkasan: '.implode(', ', array_filter([
                $losCount ? "{$losCount} LOSS" : null,
                $dgCount ? "{$dgCount} Mati Listrik/Offline" : null,
                $rxCount ? "{$rxCount} Redaman Buruk" : null,
                $recCount ? "{$recCount} Kembali Online" : null,
            ])),
            '',
            '*Daftar ONU Terdampak (maks 15):*',
        ];

        foreach (array_slice($events, 0, 15) as $ev) {
            /** @var Onu $onu */
            $onu = $ev['onu'];
            $port = $ev['port'] ?? $onu->port;
            $ifName = $port ? str_replace(['gpon-olt_', 'epon-olt_'], ['', ''], $port->interface_name).':'.$onu->onu_index : ('#'.$onu->onu_index);
            $tag = match ($ev['type']) {
                'onu_los' => 'LOS',
                'onu_dying_gasp' => strtoupper((string) ($ev['new_phase'] ?? 'OFFLINE')),
                'onu_bad_rx' => number_format((float) $onu->rx_power, 2).' dBm',
                'onu_recovery' => 'ONLINE',
                default => 'UPDATE',
            };
            $lines[] = "- [{$tag}] {$onu->name} (`{$onu->sn}`) @ {$ifName}";
        }

        if (count($events) > 15) {
            $more = count($events) - 15;
            $lines[] = "...dan {$more} ONU lainnya.";
        }

        $lines[] = '';
        $lines[] = '• Waktu: '.now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB';

        return implode("\n", $lines);
    }

    private function formatTestMessage(NotificationSetting $setting, ?Olt $olt, ?Onu $onu): string
    {
        $oltName = $olt?->name ?? 'OLT-ZTE-PUSAT';
        $onuName = $onu?->name ?? 'Budi_Santoso';
        $onuSn = $onu?->sn ?? 'ZTEGC1122334';
        $ifName = $onu?->port ? str_replace(['gpon-olt_', 'epon-olt_'], ['gpon-onu_', 'epon-onu_'], $onu->port->interface_name).':'.$onu->onu_index : 'gpon-onu_1/1/1:1';

        return implode("\n", [
            '🚨 *[TES NOTIFIKASI] ALERT ONU LOSS*',
            '',
            "• Pelanggan: *{$onuName}*",
            "• Serial (SN): `{$onuSn}`",
            "• OLT / Port: {$oltName} (`{$ifName}`)",
            '• Status: WORKING ➔ *LOS (Putus Optik)*',
            '• Redaman Terakhir: -28.40 dBm',
            '• Waktu: '.now()->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB',
            '',
            '_Integrasi notifikasi NMS OLT ZTE aktif dan siap mengirim alert otomatis._',
        ]);
    }

    private function isInCooldown(int $tenantId, int $onuId, string $eventType, int $cooldownMinutes): bool
    {
        if ($cooldownMinutes <= 0 || $eventType === 'onu_recovery') {
            return false;
        }

        return NotificationLog::where('tenant_id', $tenantId)
            ->where('onu_id', $onuId)
            ->where('event_type', $eventType)
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();
    }

    private function parseRecipients(string $raw): array
    {
        $items = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $items), fn ($v) => $v !== '')));
    }

    private function normalizeWhatsAppTarget(string $raw): array
    {
        $trimmed = trim($raw);
        if (str_contains($trimmed, '@')) {
            $num = preg_replace('/@.*$/', '', $trimmed) ?: $trimmed;
            return [$num, $trimmed];
        }

        $digits = preg_replace('/[^0-9]/', '', $trimmed) ?: $trimmed;
        if (str_starts_with($digits, '08')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return [$digits, $digits.'@s.whatsapp.net'];
    }

    private function log(int $tenantId, ?int $oltId, ?int $onuId, string $channel, string $eventType, string $recipient, string $status, string $message, ?string $error = null): void
    {
        NotificationLog::create([
            'tenant_id' => $tenantId,
            'olt_id' => $oltId,
            'onu_id' => $onuId,
            'channel' => $channel,
            'event_type' => $eventType,
            'recipient' => mb_substr($recipient, 0, 191),
            'status' => $status,
            'message' => $message,
            'error' => $error,
            'sent_at' => now(),
        ]);
    }
}
