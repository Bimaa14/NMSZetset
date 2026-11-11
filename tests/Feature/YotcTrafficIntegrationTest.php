<?php

namespace Tests\Feature;

use App\Jobs\PollOlt;
use App\Models\{AuditLog, Olt, OltOperation, OltPort, User};
use App\Services\Olt\{MikrotikWinboxClient, OltCapabilities, OltConnectionException, OltOperationService, YotcTelnetClient, YotcTrafficCollectorService, ZteTrafficCollectorService};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, DB, Http, Queue};
use Tests\TestCase;

class YotcTrafficIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::where('email', 'isp_owner@bima.test')->firstOrFail();
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'name'=>'YOTC-TRAFFIC', 'chassis_type'=>'C1600', 'ip_address'=>'192.168.80.10',
            'telnet_port'=>80, 'cli_transport'=>'http', 'connection_mode'=>'real',
            'telnet_user'=>'web-monitor', 'telnet_password'=>'fixture-web-secret',
            'snmp_port'=>161, 'connection_timeout'=>5,
            'traffic_monitoring_enabled'=>true, 'traffic_pon_ports'=>['1/1'],
            'traffic_telnet_port'=>23, 'traffic_telnet_user'=>'traffic-monitor',
            'traffic_telnet_password'=>'fixture-traffic-secret',
            'traffic_enable_password'=>'fixture-enable-secret',
        ], $overrides);
    }

    private function yotc(bool $configured = true): Olt
    {
        $olt=$this->owner()->tenant->olts()->firstOrFail();
        $olt->update([
            'chassis_type'=>'C1600', 'connection_mode'=>'real', 'cli_transport'=>'http',
            'telnet_port'=>80, 'monitoring_enabled'=>true,
            'traffic_monitoring_enabled'=>$configured, 'traffic_pon_ports'=>$configured ? ['1/1'] : [],
            'traffic_telnet_port'=>23, 'traffic_telnet_user'=>'traffic-monitor',
            'traffic_telnet_pass_enc'=>$configured ? 'fixture-traffic-secret' : null,
            'traffic_enable_pass_enc'=>$configured ? 'fixture-enable-secret' : null,
        ]);
        $olt->ports()->update(['observation_source'=>'real', 'is_present'=>true]);
        $olt->onus()->update(['observation_source'=>'real', 'is_present'=>true]);
        $port=$olt->ports()->firstOrFail();
        $port->update(['slot_no'=>1, 'port_no'=>1, 'interface_name'=>'epon-olt_1/1']);
        return $olt->fresh();
    }

    private function port(Olt $olt): OltPort
    {
        return $olt->ports()->where('interface_name', 'epon-olt_1/1')->firstOrFail();
    }

    public function test_traffic_credentials_are_encrypted_and_never_returned_or_audited(): void
    {
        config(['nms.subscription'=>false]);
        $this->actingAs($this->owner())->postJson('/olts', $this->input(['traffic_monitoring_enabled'=>false]))->assertCreated();
        $olt=Olt::where('name', 'YOTC-TRAFFIC')->firstOrFail();
        $this->assertFalse($olt->traffic_monitoring_enabled);
        $this->assertSame(['1/1'], $olt->traffic_pon_ports);
        foreach (['traffic_telnet_pass_enc'=>'fixture-traffic-secret', 'traffic_enable_pass_enc'=>'fixture-enable-secret'] as $column=>$secret) {
            $this->assertSame($secret, $olt->$column);
            $this->assertNotSame($secret, $olt->getRawOriginal($column));
            $this->assertStringNotContainsString($secret, $olt->toJson());
            $this->assertStringNotContainsString($secret, AuditLog::all()->toJson());
            $this->getJson('/olts/'.$olt->id.'/settings')->assertOk()->assertDontSee($secret);
            $this->getJson('/dashboard/data?compact=1')->assertOk()->assertDontSee($secret);
        }
        $settings=$this->getJson('/olts/'.$olt->id.'/settings')->assertOk()
            ->assertJsonPath('traffic_monitoring_enabled', false)
            ->assertJsonPath('traffic_pon_ports', ['1/1'])->json();
        foreach (['traffic_telnet_password', 'traffic_enable_password', 'traffic_telnet_pass_enc', 'traffic_enable_pass_enc'] as $field) {
            $this->assertArrayNotHasKey($field, $settings);
        }
    }

    public function test_synced_port_can_be_enabled_and_blank_edits_preserve_both_saved_traffic_secrets(): void
    {
        $olt=$this->yotc(false);
        $this->actingAs($this->owner())->putJson('/olts/'.$olt->id, $this->input([
            'name'=>$olt->name, 'ip_address'=>$olt->ip_address, 'telnet_password'=>'',
        ]))->assertOk();
        $olt=$olt->fresh();
        $this->assertTrue($olt->traffic_monitoring_enabled);
        $raw=[];
        foreach (['traffic_telnet_pass_enc', 'traffic_enable_pass_enc'] as $column) $raw[$column]=$olt->getRawOriginal($column);
        $this->actingAs($this->owner())->putJson('/olts/'.$olt->id, $this->input([
            'name'=>$olt->name, 'ip_address'=>$olt->ip_address, 'telnet_password'=>'',
            'traffic_telnet_password'=>'', 'traffic_enable_password'=>'',
        ]))->assertOk()->assertDontSee('fixture-traffic-secret')->assertDontSee('fixture-enable-secret');
        foreach ($raw as $column=>$ciphertext) $this->assertSame($ciphertext, $olt->fresh()->getRawOriginal($column));
        $this->assertTrue(app(OltCapabilities::class)->describe($olt->fresh())['features']['port_traffic']);
    }

    public function test_new_traffic_configuration_requires_ports_and_password_and_rejects_commands_as_ports(): void
    {
        config(['nms.subscription'=>false]);
        $this->actingAs($this->owner());
        foreach ([
            ['traffic_pon_ports'=>[], 'field'=>'traffic_pon_ports'],
            ['traffic_telnet_password'=>'', 'field'=>'traffic_telnet_password'],
            ['traffic_pon_ports'=>['1/1;reboot'], 'field'=>'traffic_pon_ports.0'],
        ] as $case) {
            $field=$case['field']; unset($case['field']);
            $this->postJson('/olts', $this->input($case))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        // A complete Telnet profile still needs a current PON from a saved inventory sync.
        $this->postJson('/olts', $this->input())->assertUnprocessable();
        $this->assertDatabaseMissing('olts', ['name'=>'YOTC-TRAFFIC']);
    }

    public function test_port_live_uses_yotc_collector_and_read_only_users_retain_access(): void
    {
        $olt=$this->yotc(); $port=$this->port($olt);
        $sample=['source'=>'cli', 'rx_bps'=>15870000.0, 'tx_bps'=>1480000.0, 'rx_bytes'=>null, 'tx_bytes'=>null];
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('collect', 'poll', 'coverage');
        $this->mock(YotcTrafficCollectorService::class)->shouldReceive('collect')->once()
            ->withArgs(fn (OltPort $entity) => $entity->id===$port->id && $entity->olt->tenant_id===$olt->tenant_id)
            ->andReturn($sample);
        $reader=User::where('email', 'cs@bima.test')->firstOrFail();
        $this->actingAs($reader)->postJson('/traffic/ports/'.$port->id.'/live')->assertOk()
            ->assertJsonPath('sample.source', 'cli')->assertJsonPath('sample.rx_bps', 15870000)
            ->assertJsonPath('sample.tx_bps', 1480000)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/olts/'.$olt->id.'/settings')->assertForbidden();
        $this->postJson('/olts/'.$olt->id.'/operations', ['kind'=>'traffic'])->assertForbidden();
    }

    public function test_per_onu_and_other_tenant_live_reads_fail_before_collector_io(): void
    {
        $olt=$this->yotc(); $port=$this->port($olt); $onu=$olt->onus()->current()->firstOrFail();
        $this->mock(YotcTrafficCollectorService::class)->shouldNotReceive('collect', 'poll');
        $this->mock(YotcTelnetClient::class)->shouldNotReceive('connect');
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('collect', 'poll', 'coverage');
        $this->actingAs($this->owner())->postJson('/traffic/onus/'.$onu->id.'/live')->assertUnprocessable();
        $this->actingAs(User::where('email', 'owner@nusantara.test')->firstOrFail());
        $this->postJson('/traffic/ports/'.$port->id.'/live')->assertNotFound();
        $this->getJson('/traffic/ports/'.$port->id)->assertNotFound();
        $this->assertDatabaseCount('port_traffic_logs', 0);
        $this->assertDatabaseCount('onu_traffic_logs', 0);
    }

    public function test_live_rejects_an_unconfigured_pon_without_network_io(): void
    {
        $olt=$this->yotc();
        $port=$olt->ports()->where('id', '!=', $this->port($olt)->id)->firstOrFail();
        $port->update(['slot_no'=>1, 'port_no'=>2, 'interface_name'=>'epon-olt_1/2']);
        $this->mock(YotcTelnetClient::class)->shouldNotReceive('connect');
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('collect', 'poll');
        $this->actingAs($this->owner())->postJson('/traffic/ports/'.$port->id.'/live')->assertUnprocessable();
        $this->assertDatabaseCount('port_traffic_logs', 0);
    }

    public function test_disabled_traffic_cannot_be_read_live_or_run_by_an_already_queued_job(): void
    {
        $olt=$this->yotc(); $port=$this->port($olt);
        $operation=app(OltOperationService::class)->enqueue($olt, 'traffic');
        $olt->update(['traffic_monitoring_enabled'=>false]);
        $this->mock(YotcTrafficCollectorService::class)->shouldNotReceive('collect', 'poll');
        $this->mock(YotcTelnetClient::class)->shouldNotReceive('connect');
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('collect', 'poll', 'coverage');
        $this->actingAs($this->owner())->postJson('/traffic/ports/'.$port->id.'/live')->assertUnprocessable();
        $this->postJson('/olts/'.$olt->id.'/operations', ['kind'=>'traffic'])->assertUnprocessable();
        app()->call([new PollOlt($operation->id), 'handle']);
        $this->assertSame('failed', $operation->fresh()->status);
        $this->assertNull($operation->fresh()->active_olt_id);
        $this->assertDatabaseCount('port_traffic_logs', 0);
        $this->assertSame(1, OltOperation::where('olt_id', $olt->id)->where('kind', 'traffic')->count());
    }

    public function test_queued_traffic_dispatches_to_the_yotc_collector(): void
    {
        $olt=$this->yotc();
        $operation=app(OltOperationService::class)->enqueue($olt, 'traffic');
        $this->mock(YotcTrafficCollectorService::class)->shouldReceive('poll')->once()
            ->withArgs(fn (Olt $device) => $device->id===$olt->id)->andReturn([]);
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('collect', 'poll', 'coverage');
        app()->call([new PollOlt($operation->id), 'handle']);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertNull($operation->fresh()->active_olt_id);
    }

    public function test_busy_yotc_session_requeues_the_operation_with_a_ten_second_delay(): void
    {
        // Use the local database queue so the retry's availability time is observable.
        Queue::swap(Queue::getFacadeRoot()->queue);
        $this->travelTo(now()->startOfSecond());
        $olt=$this->yotc();
        $operation=app(OltOperationService::class)->enqueue($olt, 'traffic');
        DB::table('jobs')->delete(); // The original delivery has been taken by the worker.
        $this->mock(YotcTrafficCollectorService::class)->shouldReceive('poll')->once()
            ->andThrow(new OltConnectionException('Sesi YOTC sedang digunakan.', 'read_busy'));
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('collect', 'poll', 'coverage');

        app()->call([new PollOlt($operation->id), 'handle']);

        $operation=$operation->fresh();
        $this->assertSame('queued', $operation->status);
        $this->assertSame($olt->id, $operation->active_olt_id);
        $this->assertNull($operation->error);
        $this->assertNull($operation->completed_at);
        $this->assertDatabaseCount('jobs', 1);
        $retry=DB::table('jobs')->first();
        $this->assertSame('olt', $retry->queue);
        $this->assertSame(now()->addSeconds(10)->timestamp, (int) $retry->available_at);
        $this->assertSame(PollOlt::class, json_decode($retry->payload, true)['displayName']);
        $this->assertDatabaseMissing('audit_logs', ['olt_id'=>$olt->id, 'action'=>'olt.traffic.failed']);
        $this->assertDatabaseCount('port_traffic_logs', 0);
    }

    public function test_yotc_scheduler_uses_sixty_second_floor_without_zte_coverage(): void
    {
        $olt=$this->yotc();
        Olt::where('id', '!=', $olt->id)->update(['monitoring_enabled'=>false]);
        config(['olt.yotc_traffic_interval'=>1, 'olt.traffic_interval'=>1,
            'olt.state_interval'=>1, 'olt.names_interval'=>1, 'olt.optical_interval'=>1, 'olt.system_interval'=>1]);
        $olt->update(['state_observed_at'=>now(), 'names_observed_at'=>now(), 'optical_observed_at'=>now(),
            'system_observed_at'=>now(), 'traffic_observed_at'=>now()->subSeconds(59)]);
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('coverage', 'poll', 'collect');
        $service=app(OltOperationService::class);
        $this->assertSame(0, $service->tick());
        $this->assertDatabaseCount('olt_operations', 0);
        $this->travel(2)->seconds();
        $this->assertSame(1, $service->tick());
        $this->assertDatabaseHas('olt_operations', ['olt_id'=>$olt->id, 'kind'=>'traffic', 'status'=>'queued']);
        Queue::assertPushed(PollOlt::class);
    }

    public function test_yotc_port_history_keeps_cli_samples_and_filters_simulator_and_cross_tenant_rows(): void
    {
        $olt=$this->yotc(); $port=$this->port($olt); $at=now()->subSeconds(10);
        $base=['olt_port_id'=>$port->id, 'olt_id'=>$olt->id, 'tenant_id'=>$olt->tenant_id,
            'source'=>'cli', 'recorded_at'=>$at, 'rx_bps'=>15870000, 'tx_bps'=>1480000,
            'rx_bytes'=>null, 'tx_bytes'=>null];
        DB::table('port_traffic_logs')->insert($base);
        DB::table('port_traffic_logs')->insert(array_replace($base, ['source'=>'simulator', 'rx_bps'=>999999999]));
        DB::table('port_traffic_logs')->insert(array_replace($base, ['tenant_id'=>User::where('email', 'owner@nusantara.test')->firstOrFail()->tenant_id]));
        $this->actingAs($this->owner())->getJson('/traffic/ports/'.$port->id.'?minutes=5')->assertOk()
            ->assertJsonPath('sample_count', 1)->assertJsonPath('statistics.rx.current', 15870000)
            ->assertJsonPath('statistics.tx.current', 1480000)->assertJsonPath('statistics.rx.peak', 15870000)
            ->assertJsonPath('volume_supported', false)->assertJsonPath('statistics.rx.volume_bytes', null)
            ->assertJsonPath('statistics.tx.volume_bytes', null);
    }

    public function test_mikrotik_pppoe_onu_traffic_collects_persists_and_calculates_volume(): void
    {
        $olt = $this->yotc();
        $onu = $olt->onus()->current()->firstOrFail();
        $onu->update(['sn' => '70:2E:22:63:08:BC', 'name' => 'RETAIL-BIMA-TEST', 'phase_state' => 'working']);

        config([
            'olt.mikrotik_routers' => [
                'cikuray' => [
                    'name' => 'SMY-CORE-BDG-CIKURAY',
                    'host' => '198.51.100.10',
                    'port' => 60193,
                    'user' => 'ro-test',
                    'password' => 'fixture-ro-pass',
                    'timeout' => 5,
                    'service_filter' => 'RETAIL-TAMANSARI',
                ],
            ],
        ]);

        $cap = app(OltCapabilities::class)->describe($olt->fresh());
        $this->assertTrue($cap['features']['onu_traffic']);
        $this->assertNotContains('onu_traffic', $cap['unsupported']);

        Cache::put('nms:mikrotik-router-warm:'.$olt->id.':cikuray', true, 600);

        $winbox = $this->mock(MikrotikWinboxClient::class);
        $winbox->shouldReceive('connect')->once()->with('198.51.100.10', 60193, 'ro-test', 'fixture-ro-pass', 5.0);
        $winbox->shouldReceive('runCommand')->once()
            ->with('/ppp active print detail without-paging where service="RETAIL-TAMANSARI"', 5.0, 0.25)
            ->andReturn("Flags: R - radius\n ;;; BIMA TEST, TAMANSARI\n 0 R name=\"702E226308BD@simaya.net.id\" service=\"RETAIL-TAMANSARI\" caller-id=\"70:2E:22:63:08:BD\" address=10.111.1.10 uptime=1d2h3m4s session-id=0x81000001\n");
        $winbox->shouldReceive('runCommand')->once()
            ->with('/interface print stats without-paging where type="pppoe-in"', 5.0, 0.25)
            ->andReturn(" #     NAME                                                                                       RX-BYTE           TX-BYTE     RX-PACKET     TX-PACKET\n 0 DR  <pppoe-702E226308BD@simaya.net.id>                                                  12 000 000 000    70 000 000 000     1 000 000     5 000 000\n");
        $winbox->shouldReceive('runCommand')->once()
            ->with('/queue simple print stats without-paging', 5.0, 0.25)
            ->andReturn("Flags: X - disabled, I - invalid, D - dynamic\n 0  D name=\"<pppoe-702E226308BD@simaya.net.id>\" target=<pppoe-702E226308BD@simaya.net.id> rate=2.5Mbps/18.4Mbps bytes=12000000000/70000000000\n");
        $winbox->shouldReceive('close')->once();

        $this->actingAs($this->owner())->postJson('/traffic/onus/'.$onu->id.'/live')->assertOk()
            ->assertJsonPath('sample.source', 'cli')
            ->assertJsonPath('sample.rx_bps', 18400000)
            ->assertJsonPath('sample.tx_bps', 2500000)
            ->assertJsonPath('sample.rx_bytes', '70000000000')
            ->assertJsonPath('sample.tx_bytes', '12000000000')
            ->assertJsonPath('sample.pppoe_user', '702E226308BD@simaya.net.id');

        $onu = $onu->fresh();
        $this->assertSame('702E226308BD@simaya.net.id', $onu->pppoe_user);
        $this->assertSame(18400000.0, $onu->download_bps);
        $this->assertSame(2500000.0, $onu->upload_bps);
        $this->assertDatabaseCount('onu_traffic_logs', 1);

        DB::table('onu_traffic_logs')->insert([
            'tenant_id' => $olt->tenant_id,
            'olt_id' => $olt->id,
            'onu_id' => $onu->id,
            'source' => 'cli',
            'rx_bps' => 16000000,
            'tx_bps' => 2000000,
            'download_bps' => 16000000,
            'upload_bps' => 2000000,
            'rx_bytes' => '69880000000',
            'tx_bytes' => '11985000000',
            'recorded_at' => now()->subSeconds(60),
            'observed_at' => now()->subSeconds(60),
        ]);

        $this->actingAs($this->owner())->getJson('/traffic/onus/'.$onu->id.'?minutes=5')->assertOk()
            ->assertJsonPath('volume_supported', true)
            ->assertJsonPath('sample_count', 2)
            ->assertJsonPath('statistics.rx.volume_bytes', 120000000)
            ->assertJsonPath('statistics.tx.volume_bytes', 15000000);
    }
}
