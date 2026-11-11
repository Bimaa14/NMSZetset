<?php

namespace App\Http\Controllers;

use App\Models\{NotificationLog, NotificationSetting};
use App\Services\{AuditRecorder, NotificationService};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->allows('read'), 403);
        $setting = NotificationSetting::forTenant($request->user()->tenant_id);

        return response()->json($this->payload($setting));
    }

    public function update(Request $request, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $setting = NotificationSetting::forTenant($request->user()->tenant_id);

        $data = $request->validate([
            'telegram_enabled' => ['required', 'boolean'],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'telegram_thread_id' => ['nullable', 'string', 'max:64'],
            'whatsapp_enabled' => ['required', 'boolean'],
            'whatsapp_provider' => ['required', 'string', Rule::in(['baileys', 'fonnte', 'wablas', 'custom'])],
            'whatsapp_endpoint_url' => ['nullable', 'url', 'max:500'],
            'whatsapp_api_key' => ['nullable', 'string', 'max:255'],
            'whatsapp_session_id' => ['nullable', 'string', 'max:100'],
            'whatsapp_recipients' => ['nullable', 'string', 'max:1000'],
            'notify_on_los' => ['required', 'boolean'],
            'notify_on_dying_gasp' => ['required', 'boolean'],
            'notify_on_recovery' => ['required', 'boolean'],
            'notify_on_bad_rx' => ['required', 'boolean'],
            'bad_rx_threshold' => ['required', 'numeric', 'between:-45,-10'],
            'cooldown_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        $botToken = trim((string) ($data['telegram_bot_token'] ?? ''));
        $waApiKey = trim((string) ($data['whatsapp_api_key'] ?? ''));

        if ($data['telegram_enabled']) {
            if ($botToken === '' && empty($setting->telegram_bot_token_enc)) {
                throw ValidationException::withMessages(['telegram_bot_token' => 'Bot Token Telegram wajib diisi saat Telegram diaktifkan.']);
            }
            if (trim((string) ($data['telegram_chat_id'] ?? '')) === '') {
                throw ValidationException::withMessages(['telegram_chat_id' => 'Chat ID Telegram wajib diisi saat Telegram diaktifkan.']);
            }
        }

        if ($data['whatsapp_enabled']) {
            if (trim((string) ($data['whatsapp_recipients'] ?? '')) === '') {
                throw ValidationException::withMessages(['whatsapp_recipients' => 'Nomor tujuan WhatsApp atau Group JID wajib diisi saat WhatsApp diaktifkan.']);
            }
            if (in_array($data['whatsapp_provider'], ['baileys', 'custom'], true) && trim((string) ($data['whatsapp_endpoint_url'] ?? '')) === '') {
                throw ValidationException::withMessages(['whatsapp_endpoint_url' => 'Endpoint URL Gateway (Node.js Baileys / Webhook) wajib diisi.']);
            }
        }

        $updates = [
            'telegram_enabled' => (bool) $data['telegram_enabled'],
            'telegram_chat_id' => trim((string) ($data['telegram_chat_id'] ?? '')) ?: null,
            'telegram_thread_id' => trim((string) ($data['telegram_thread_id'] ?? '')) ?: null,
            'whatsapp_enabled' => (bool) $data['whatsapp_enabled'],
            'whatsapp_provider' => $data['whatsapp_provider'],
            'whatsapp_endpoint_url' => trim((string) ($data['whatsapp_endpoint_url'] ?? '')) ?: null,
            'whatsapp_session_id' => trim((string) ($data['whatsapp_session_id'] ?? '')) ?: 'default',
            'whatsapp_recipients' => trim((string) ($data['whatsapp_recipients'] ?? '')) ?: null,
            'notify_on_los' => (bool) $data['notify_on_los'],
            'notify_on_dying_gasp' => (bool) $data['notify_on_dying_gasp'],
            'notify_on_recovery' => (bool) $data['notify_on_recovery'],
            'notify_on_bad_rx' => (bool) $data['notify_on_bad_rx'],
            'bad_rx_threshold' => (float) $data['bad_rx_threshold'],
            'cooldown_minutes' => (int) $data['cooldown_minutes'],
        ];

        if ($botToken !== '') {
            $updates['telegram_bot_token_enc'] = $botToken;
        }
        if ($waApiKey !== '') {
            $updates['whatsapp_api_key_enc'] = $waApiKey;
        }

        $setting->update($updates);
        $audit->record($request, 'notification.settings.update', 'Integrasi WA/Telegram');

        return response()->json([
            'message' => 'Pengaturan notifikasi WhatsApp & Telegram berhasil disimpan.',
            ...$this->payload($setting->fresh()),
        ]);
    }

    public function test(Request $request, NotificationService $service, AuditRecorder $audit)
    {
        abort_unless($request->user()->allows('operate'), 403);
        $data = $request->validate([
            'channel' => ['required', 'string', Rule::in(['whatsapp', 'telegram'])],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'telegram_thread_id' => ['nullable', 'string', 'max:64'],
            'whatsapp_provider' => ['nullable', 'string', Rule::in(['baileys', 'fonnte', 'wablas', 'custom'])],
            'whatsapp_endpoint_url' => ['nullable', 'url', 'max:500'],
            'whatsapp_api_key' => ['nullable', 'string', 'max:255'],
            'whatsapp_session_id' => ['nullable', 'string', 'max:100'],
            'whatsapp_recipients' => ['nullable', 'string', 'max:1000'],
        ]);

        $setting = NotificationSetting::forTenant($request->user()->tenant_id);

        // Allow testing unsaved draft fields from the UI
        if (! empty($data['telegram_bot_token'])) {
            $setting->telegram_bot_token_enc = trim($data['telegram_bot_token']);
        }
        if (array_key_exists('telegram_chat_id', $data) && $data['telegram_chat_id'] !== null) {
            $setting->telegram_chat_id = trim($data['telegram_chat_id']);
        }
        if (array_key_exists('telegram_thread_id', $data)) {
            $setting->telegram_thread_id = trim((string) $data['telegram_thread_id']);
        }
        if (! empty($data['whatsapp_provider'])) {
            $setting->whatsapp_provider = $data['whatsapp_provider'];
        }
        if (array_key_exists('whatsapp_endpoint_url', $data) && $data['whatsapp_endpoint_url'] !== null) {
            $setting->whatsapp_endpoint_url = trim($data['whatsapp_endpoint_url']);
        }
        if (! empty($data['whatsapp_api_key'])) {
            $setting->whatsapp_api_key_enc = trim($data['whatsapp_api_key']);
        }
        if (! empty($data['whatsapp_session_id'])) {
            $setting->whatsapp_session_id = trim($data['whatsapp_session_id']);
        }
        if (array_key_exists('whatsapp_recipients', $data) && $data['whatsapp_recipients'] !== null) {
            $setting->whatsapp_recipients = trim($data['whatsapp_recipients']);
        }

        $channelLabel = $data['channel'] === 'whatsapp' ? 'WhatsApp' : 'Telegram';
        try {
            $results = $service->sendTest($setting, $data['channel']);
            $failed = array_values(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'failed'));
            $audit->record($request, 'notification.test.'.$data['channel'], $channelLabel);

            if ($failed) {
                $firstError = $failed[0]['error'] ?? 'Gagal menghubungi gateway.';
                return response()->json([
                    'message' => "Pengiriman tes {$channelLabel} gagal: {$firstError}",
                    ...$this->payload(NotificationSetting::forTenant($request->user()->tenant_id)),
                ], 422);
            }

            return response()->json([
                'message' => "Pesan tes {$channelLabel} berhasil dikirim ke ".count($results).' tujuan.',
                ...$this->payload(NotificationSetting::forTenant($request->user()->tenant_id)),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => "Gagal mengirim tes {$channelLabel}: ".$e->getMessage(),
                ...$this->payload(NotificationSetting::forTenant($request->user()->tenant_id)),
            ], 422);
        }
    }

    private function payload(NotificationSetting $setting): array
    {
        return [
            'settings' => [
                'telegram_enabled' => $setting->telegram_enabled,
                'has_telegram_bot_token' => ! empty($setting->telegram_bot_token_enc),
                'telegram_chat_id' => $setting->telegram_chat_id ?? '',
                'telegram_thread_id' => $setting->telegram_thread_id ?? '',
                'whatsapp_enabled' => $setting->whatsapp_enabled,
                'whatsapp_provider' => $setting->whatsapp_provider ?: 'baileys',
                'whatsapp_endpoint_url' => $setting->whatsapp_endpoint_url ?? 'http://127.0.0.1:3001/send-message',
                'has_whatsapp_api_key' => ! empty($setting->whatsapp_api_key_enc),
                'whatsapp_session_id' => $setting->whatsapp_session_id ?: 'default',
                'whatsapp_recipients' => $setting->whatsapp_recipients ?? '',
                'notify_on_los' => $setting->notify_on_los,
                'notify_on_dying_gasp' => $setting->notify_on_dying_gasp,
                'notify_on_recovery' => $setting->notify_on_recovery,
                'notify_on_bad_rx' => $setting->notify_on_bad_rx,
                'bad_rx_threshold' => (float) ($setting->bad_rx_threshold ?? config('nms.bad_rx_threshold', -27.00)),
                'cooldown_minutes' => (int) ($setting->cooldown_minutes ?? 15),
            ],
            'logs' => NotificationLog::where('tenant_id', $setting->tenant_id)->latest('id')->limit(30)->get(),
        ];
    }
}
