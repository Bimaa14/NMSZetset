<?php

namespace Tests\Feature;

use App\Models\{NotificationLog, NotificationSetting, Olt, OltPort, Onu, User};
use App\Services\NotificationService;
use App\Services\Olt\{OltConnectionException, ZteOnuDetailService, ZteTelnetClient};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function owner(): User
    {
        return User::where('email', 'isp_owner@bima.test')->firstOrFail();
    }

    public function test_owner_and_noc_can_configure_whatsapp_baileys_and_telegram_while_cs_cannot(): void
    {
        $owner = $this->owner();
        $cs = User::where('role', 'cs')->firstOrFail();

        $payload = [
            'telegram_enabled' => true,
            'telegram_bot_token' => '123456:ABCDEF-bot-token',
            'telegram_chat_id' => '-100987654321',
            'telegram_thread_id' => '12',
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'baileys',
            'whatsapp_endpoint_url' => 'http://127.0.0.1:3001/send-message',
            'whatsapp_api_key' => 'baileys-secret-key',
            'whatsapp_session_id' => 'olt-noc',
            'whatsapp_recipients' => '081234567890, 120363001122@g.us',
            'notify_on_los' => true,
            'notify_on_dying_gasp' => true,
            'notify_on_recovery' => true,
            'notify_on_bad_rx' => true,
            'bad_rx_threshold' => -27.5,
            'cooldown_minutes' => 10,
        ];

        $this->actingAs($cs)->putJson('/notifications/settings', $payload)->assertForbidden();

        $this->actingAs($owner)->putJson('/notifications/settings', $payload)
            ->assertOk()
            ->assertJsonPath('settings.whatsapp_enabled', true)
            ->assertJsonPath('settings.telegram_enabled', true)
            ->assertJsonPath('settings.has_whatsapp_api_key', true)
            ->assertJsonPath('settings.has_telegram_bot_token', true)
            ->assertJsonMissing(['baileys-secret-key'])
            ->assertJsonMissing(['123456:ABCDEF-bot-token']);

        $setting = NotificationSetting::forTenant($owner->tenant_id);
        $this->assertSame('baileys-secret-key', $setting->whatsapp_api_key_enc);
        $this->assertSame('123456:ABCDEF-bot-token', $setting->telegram_bot_token_enc);
    }

    public function test_test_notification_sends_baileys_compatible_payload_and_telegram_message(): void
    {
        Http::fake([
            'http://127.0.0.1:3001/*' => Http::response(['status' => true, 'message' => 'queued'], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/notifications/test', [
            'channel' => 'whatsapp',
            'whatsapp_provider' => 'baileys',
            'whatsapp_endpoint_url' => 'http://127.0.0.1:3001/send-message',
            'whatsapp_api_key' => 'secret123',
            'whatsapp_session_id' => 'ubuntu-wa',
            'whatsapp_recipients' => '081299998888, 120363999@g.us',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:3001/send-message'
                && $request->hasHeader('x-api-key', 'secret123')
                && $request['number'] === '6281299998888'
                && $request['jid'] === '6281299998888@s.whatsapp.net'
                && $request['sessionId'] === 'ubuntu-wa'
                && str_contains($request['message'], 'ALERT ONU LOSS');
        });

        $this->actingAs($owner)->postJson('/notifications/test', [
            'channel' => 'telegram',
            'telegram_bot_token' => '999888:XYZ',
            'telegram_chat_id' => '-100112233',
        ])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/bot999888:XYZ/sendMessage')
                && $request['chat_id'] === '-100112233'
                && str_contains($request['text'], 'ALERT ONU LOSS');
        });

        $this->assertSame(3, NotificationLog::where('tenant_id', $owner->tenant_id)->where('status', 'sent')->count());
    }

    public function test_onu_los_dying_gasp_bad_rx_and_recovery_trigger_notifications_with_cooldown(): void
    {
        Http::fake([
            'http://127.0.0.1:3001/*' => Http::response(['status' => true], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $owner = $this->owner();
        $olt = Olt::where('tenant_id', $owner->tenant_id)->firstOrFail();
        $port = OltPort::where('olt_id', $olt->id)->firstOrFail();
        $onu = Onu::where('olt_id', $olt->id)->where('olt_port_id', $port->id)->firstOrFail();

        $setting = NotificationSetting::forTenant($owner->tenant_id);
        $setting->update([
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'baileys',
            'whatsapp_endpoint_url' => 'http://127.0.0.1:3001/send-message',
            'whatsapp_recipients' => '081122334455',
            'telegram_enabled' => true,
            'telegram_bot_token_enc' => '111222:BOT',
            'telegram_chat_id' => '-100555666',
            'notify_on_los' => true,
            'notify_on_dying_gasp' => true,
            'notify_on_recovery' => true,
            'notify_on_bad_rx' => true,
            'bad_rx_threshold' => -27.0,
            'cooldown_minutes' => 15,
        ]);

        $service = app(NotificationService::class);

        // 1. ONU goes LOS -> sends to both WA & Telegram (2 logs)
        $onu->update(['phase_state' => 'los']);
        $service->dispatchBatch($olt, [
            ['onu' => $onu, 'port' => $port, 'old_phase' => 'working', 'new_phase' => 'los'],
        ]);
        $this->assertSame(2, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_los')->count());

        // 2. Duplicate LOS within 15m cooldown -> skipped!
        $service->dispatchBatch($olt, [
            ['onu' => $onu, 'port' => $port, 'old_phase' => 'working', 'new_phase' => 'los'],
        ]);
        $this->assertSame(2, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_los')->count());

        // 3. ONU recovers (los -> working) -> bypasses cooldown & sends recovery!
        $onu->update(['phase_state' => 'working', 'rx_power' => -28.5]);
        $service->dispatchBatch($olt, [
            ['onu' => $onu, 'port' => $port, 'old_phase' => 'los', 'new_phase' => 'working'],
        ], [
            ['onu' => $onu, 'port' => $port, 'old_rx' => -24.0, 'new_rx' => -28.5],
        ]);
        $this->assertSame(2, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_recovery')->count());
        $this->assertSame(2, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_bad_rx')->count());

        // 4. Subsequent LOS after recovery is treated as a fresh outage (not blocked by prior outage cooldown)
        $onu->update(['phase_state' => 'los']);
        $service->dispatchBatch($olt, [
            ['onu' => $onu, 'port' => $port, 'old_phase' => 'working', 'new_phase' => 'los'],
        ]);
        $this->assertSame(4, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_los')->count());

        // 5. Recovery after ZTE intermediate boot states (sync-mib / logging) also notifies
        $onu->update(['phase_state' => 'working']);
        $service->dispatchBatch($olt, [
            ['onu' => $onu, 'port' => $port, 'old_phase' => 'sync-mib', 'new_phase' => 'working'],
        ]);
        $this->assertSame(4, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_recovery')->count());
    }

    public function test_librenms_custom_text_plain_transport_and_onu_detail_refresh_trigger_notifications(): void
    {
        Http::fake([
            'http://172.16.225.72:3000/*' => Http::response(['status' => true], 200),
        ]);

        $owner = $this->owner();
        $olt = Olt::where('tenant_id', $owner->tenant_id)->firstOrFail();
        $olt->update(['connection_mode' => 'real']);
        $port = OltPort::where('olt_id', $olt->id)->firstOrFail();
        $onu = Onu::where('olt_id', $olt->id)->where('olt_port_id', $port->id)->firstOrFail();
        $onu->update(['observation_source' => 'real', 'phase_state' => 'working']);

        $setting = NotificationSetting::forTenant($owner->tenant_id);
        $setting->update([
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'custom',
            'whatsapp_endpoint_url' => 'http://172.16.225.72:3000/send-message',
            'whatsapp_recipients' => '120363214923632683@g.us',
            'notify_on_los' => true,
            'notify_on_recovery' => true,
            'cooldown_minutes' => 15,
        ]);

        $phase = 'LOS';
        $mock = \Mockery::mock(ZteTelnetClient::class);
        $mock->shouldReceive('connect', 'close', 'addSecrets')->andReturnNull();
        $mock->shouldReceive('command')->andReturnUsing(function (string $command) use ($onu, &$phase) {
            if (str_starts_with($command, 'show gpon onu detail-info')) {
                return "Serial number: {$onu->sn}\nPhase state: {$phase}\n";
            }
            if (str_starts_with($command, 'show pon power attenuation')) {
                return "down Tx : 2.50(dBm) Rx : -21.00(dBm)\n";
            }
            throw new OltConnectionException('Unsupported command', 'command_rejected');
        });
        $this->app->instance(ZteTelnetClient::class, $mock);

        $detailService = app(ZteOnuDetailService::class);
        $detailService->refresh($onu->fresh(), 'info');

        $this->assertSame(1, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_los')->where('status', 'sent')->count());

        $phase = 'working';
        $detailService->refresh($onu->fresh(), 'info');

        $this->assertSame(1, NotificationLog::where('onu_id', $onu->id)->where('event_type', 'onu_recovery')->where('status', 'sent')->count());

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'http://172.16.225.72:3000/send-message?target=120363214923632683%40g.us')
                && str_contains(strtolower($request->header('Content-Type')[0] ?? ''), 'text/plain');
        });
    }
}
