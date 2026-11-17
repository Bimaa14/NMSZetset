<?php

namespace Tests\Feature;

use App\Models\{NotificationLog, NotificationSetting, Olt, OltPort, Onu, PowerMonitor, Tag, User};
use App\Services\{NotificationService, PowerMonitorService, PowerProbeService};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PowerMonitoringTest extends TestCase
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

    public function test_ping_power_monitor_crud_rbac_and_separate_group_alerts(): void
    {
        Http::fake([
            'http://172.16.225.72:3000/*' => Http::response(['status' => true], 200),
        ]);

        $reachable = true;
        $probe = \Mockery::mock(PowerProbeService::class);
        $probe->shouldReceive('ping')->andReturnUsing(function () use (&$reachable) {
            return $reachable
                ? ['reachable' => true, 'latency_ms' => 2.45, 'packet_loss' => 0, 'error' => null]
                : ['reachable' => false, 'latency_ms' => null, 'packet_loss' => 100, 'error' => 'ICMP Ping Timeout (100% packet loss)'];
        });
        $this->app->instance(PowerProbeService::class, $probe);

        $owner = $this->owner();
        $cs = User::where('role', 'cs')->firstOrFail();

        $this->actingAs($cs)->postJson('/power-monitors', [
            'name' => 'MONITORING LISTRIK CIPAKU',
            'monitor_type' => 'ping',
            'ip_address' => '172.16.155.253',
            'is_enabled' => true,
        ])->assertForbidden();

        $this->actingAs($owner)->putJson('/notifications/settings', [
            'telegram_enabled' => false,
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'custom',
            'whatsapp_endpoint_url' => 'http://172.16.225.72:3000/send-message',
            'whatsapp_recipients' => '1203630000001@g.us',
            'notify_on_los' => true,
            'notify_on_dying_gasp' => true,
            'notify_on_recovery' => true,
            'notify_on_bad_rx' => true,
            'bad_rx_threshold' => -27,
            'cooldown_minutes' => 15,
        ])->assertOk();

        $this->actingAs($owner)->putJson('/power-monitors/settings', [
            'power_notify_enabled' => true,
            'power_whatsapp_recipients' => '1203639999999@g.us',
            'power_cooldown_minutes' => 5,
        ])->assertOk();

        // Create sensor -> initial ping sets status = up without spurious recovery alert
        $create = $this->actingAs($owner)->postJson('/power-monitors', [
            'name' => 'MONITORING LISTRIK CIPAKU',
            'group_name' => 'MONITORING-LISTRIK-ONU-BANDUNG',
            'location' => 'Cipaku, Bandung',
            'monitor_type' => 'ping',
            'ip_address' => '172.16.155.253',
            'is_enabled' => true,
        ])->assertCreated()
            ->assertJsonPath('summary.up', 1)
            ->assertJsonPath('monitors.0.status', 'up')
            ->assertJsonPath('monitors.0.last_latency_ms', 2.45);

        $monitorId = $create->json('monitors.0.id');
        $this->assertSame(0, NotificationLog::where('power_monitor_id', $monitorId)->count());

        // Power outage (up -> down) -> notifies dedicated power WA group
        $reachable = false;
        app(PowerMonitorService::class)->checkOne(PowerMonitor::findOrFail($monitorId));

        $this->assertDatabaseHas('power_monitors', ['id' => $monitorId, 'status' => 'down']);
        $this->assertSame(1, NotificationLog::where('power_monitor_id', $monitorId)->where('event_type', 'power_down')->where('recipient', '1203639999999@g.us')->where('status', 'sent')->count());

        // Power restored (down -> up) -> notifies recovery to power WA group
        $reachable = true;
        app(PowerMonitorService::class)->checkOne(PowerMonitor::findOrFail($monitorId));

        $this->assertDatabaseHas('power_monitors', ['id' => $monitorId, 'status' => 'up']);
        $this->assertSame(1, NotificationLog::where('power_monitor_id', $monitorId)->where('event_type', 'power_up')->where('recipient', '1203639999999@g.us')->where('status', 'sent')->count());

        Http::assertSent(fn ($req) => str_contains($req->url(), 'target=1203639999999%40g.us') && str_contains($req->body(), 'LISTRIK PADAM'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'target=1203639999999%40g.us') && str_contains($req->body(), 'MENYALA KEMBALI'));

        // Tenant isolation check
        $otherOwner = User::where('email', 'owner@nusantara.test')->firstOrFail();
        $this->actingAs($otherOwner)->deleteJson('/power-monitors/'.$monitorId)->assertNotFound();
    }

    public function test_onu_linked_and_power_tagged_onus_trigger_power_alerts(): void
    {
        Http::fake([
            'http://172.16.225.72:3000/*' => Http::response(['status' => true], 200),
        ]);

        $owner = $this->owner();
        $olt = Olt::where('tenant_id', $owner->tenant_id)->firstOrFail();
        $port = OltPort::where('olt_id', $olt->id)->firstOrFail();
        $onus = Onu::where('olt_id', $olt->id)->where('olt_port_id', $port->id)->take(2)->get();

        $setting = NotificationSetting::forTenant($owner->tenant_id);
        $setting->update([
            'whatsapp_enabled' => true,
            'whatsapp_provider' => 'custom',
            'whatsapp_endpoint_url' => 'http://172.16.225.72:3000/send-message',
            'whatsapp_recipients' => '1203630000001@g.us',
            'power_notify_enabled' => true,
            'power_whatsapp_recipients' => '1203638888888@g.us',
        ]);

        $linkedMonitor = PowerMonitor::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'ONU_MONITORING_LISTRIK_GUCI',
            'group_name' => 'MONITORING-LISTRIK-ONU-BANDUNG',
            'monitor_type' => 'onu',
            'onu_id' => $onus[0]->id,
            'status' => 'up',
            'is_enabled' => true,
        ]);

        $tag = Tag::create(['tenant_id' => $owner->tenant_id, 'tag_name' => 'Monitoring Listrik', 'color_hex' => '#f59e0b']);
        $onus[1]->tags()->sync([$tag->id]);

        $service = app(NotificationService::class);
        $service->dispatchBatch($olt, [
            ['onu' => $onus[0], 'port' => $port, 'old_phase' => 'working', 'new_phase' => 'dying-gasp'],
            ['onu' => $onus[1], 'port' => $port, 'old_phase' => 'working', 'new_phase' => 'poweroff'],
        ]);

        $this->assertDatabaseHas('power_monitors', ['id' => $linkedMonitor->id, 'status' => 'down']);
        $this->assertSame(1, NotificationLog::where('power_monitor_id', $linkedMonitor->id)->where('event_type', 'power_down')->where('recipient', '1203638888888@g.us')->count());
        $this->assertSame(1, NotificationLog::where('onu_id', $onus[1]->id)->where('event_type', 'power_down')->where('recipient', '1203638888888@g.us')->count());

        // Recovery of linked ONU
        $service->dispatchBatch($olt, [
            ['onu' => $onus[0], 'port' => $port, 'old_phase' => 'dying-gasp', 'new_phase' => 'working'],
        ]);

        $this->assertDatabaseHas('power_monitors', ['id' => $linkedMonitor->id, 'status' => 'up']);
        $this->assertSame(1, NotificationLog::where('power_monitor_id', $linkedMonitor->id)->where('event_type', 'power_up')->where('recipient', '1203638888888@g.us')->count());
    }
}
