<?php

namespace Tests\Feature;

use App\Models\{Olt, OltOperation, Onu, Tag, User};
use App\Services\Olt\{OltCapabilities, OltOperationService, ZteSnmpDriver, ZteSshClient, ZteTelnetClient, ZteTelnetDriver, ZteTrafficCollectorService};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class YotcCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Queue::fake();
    }

    private function owner(): User { return User::where('email', 'isp_owner@bima.test')->firstOrFail(); }

    private function yotc(): Olt
    {
        $olt=$this->owner()->tenant->olts()->firstOrFail();
        $olt->update(['chassis_type'=>'C1600','connection_mode'=>'real','monitoring_enabled'=>true]);
        $olt->ports()->update(['observation_source'=>'real']);
        $olt->onus()->update(['observation_source'=>'real']);
        return $olt->fresh();
    }

    public function test_monitoring_support_does_not_authorize_zte_commands(): void
    {
        $olt=$this->yotc();$capabilities=app(OltCapabilities::class);$cap=$capabilities->describe($olt);
        $this->assertTrue($cap['family_supported']);$this->assertSame('EPON',$cap['protocol']);
        foreach(['monitor','inventory','names','optics','system'] as $feature)$this->assertTrue($cap['features'][$feature]);
        foreach(['provision','onu_configuration','onu_restore','cli','reboot','backup','traffic'] as $feature)$this->assertFalse($cap['features'][$feature]);
        try{$capabilities->requireSupported($olt);$this->fail('C1600 was admitted to ZTE command paths.');}
        catch(HttpExceptionInterface $e){$this->assertSame(422,$e->getStatusCode());}
        try{app(ZteTelnetDriver::class)->client($olt);$this->fail('A ZTE client was selected for C1600.');}
        catch(HttpExceptionInterface $e){$this->assertSame(422,$e->getStatusCode());}
        $this->actingAs($this->owner())->getJson('/olts/'.$olt->id.'/capabilities')->assertOk()
            ->assertJsonPath('features.sync',true)->assertJsonPath('features.cli',false)
            ->assertJsonPath('features.reboot',false)->assertJsonPath('features.traffic',false);
        $olt->chassis_type='C320';$zte=$capabilities->describe($olt);
        foreach(['monitor','cli','reboot','backup','traffic','provision','onu_configuration','onu_restore'] as $feature)$this->assertTrue($zte['features'][$feature]);
    }

    public function test_configured_traffic_only_authorizes_pon_reads_and_never_zte_controls(): void
    {
        $olt=$this->yotc();
        $olt->update(['traffic_monitoring_enabled'=>true, 'traffic_pon_ports'=>['1/1'],
            'traffic_telnet_pass_enc'=>'fixture-traffic-secret']);
        $capabilities=app(OltCapabilities::class);
        $features=$capabilities->describe($olt->fresh())['features'];
        foreach (['traffic','port_traffic'] as $feature) $this->assertTrue($features[$feature]);
        foreach (['onu_traffic','cli','reboot','backup','provision','onu_configuration','onu_restore'] as $feature) {
            $this->assertFalse($features[$feature]);
        }
        $this->actingAs($this->owner())->getJson('/olts/'.$olt->id.'/capabilities')->assertOk()
            ->assertJsonPath('features.traffic',true)->assertJsonPath('features.port_traffic',true)
            ->assertJsonPath('features.onu_traffic',false)->assertJsonPath('features.cli',false);
        foreach ([
            ['traffic_monitoring_enabled'=>false],
            ['traffic_monitoring_enabled'=>true,'traffic_pon_ports'=>[]],
            ['traffic_pon_ports'=>['1/1'],'traffic_telnet_pass_enc'=>null],
        ] as $missing) {
            $olt->update($missing);
            $features=$capabilities->describe($olt->fresh())['features'];
            $this->assertFalse($features['traffic']);
            $this->assertFalse($features['port_traffic']);
            $this->assertFalse($features['onu_traffic']);
        }
    }

    public function test_unsupported_device_actions_fail_before_network_io_or_local_mutation(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $olt=$this->yotc();$onu=$olt->onus()->current()->firstOrFail();$before=$onu->only(['name','is_present','phase_state','admin_state']);
        $this->mock(ZteTelnetClient::class)->shouldNotReceive('connect');
        $this->mock(ZteSshClient::class)->shouldNotReceive('connect');
        $snmp=$this->mock(ZteSnmpDriver::class);$snmp->shouldNotReceive('get','walk','system');
        $this->actingAs($this->owner());
        $requests=[
            ['POST','/onus/'.$onu->id.'/reboot',[]],
            ['PUT','/onus/'.$onu->id.'/name',['name'=>'FORBIDDEN']],
            ['PUT','/onus/'.$onu->id.'/admin',['enabled'=>false]],
            ['DELETE','/onus/'.$onu->id,[]],
            ['POST','/onus/'.$onu->id.'/detail/refresh',['section'=>'wan']],
            ['POST','/onus/'.$onu->id.'/detail/configure',['kind'=>'factory','confirmed'=>true]],
            ['POST','/onus/'.$onu->id.'/configuration-tool/refresh',[]],
            ['POST','/onus/'.$onu->id.'/configuration-tool/preview',['kind'=>'tcont','action'=>'delete','id'=>1]],
            ['POST','/onus/'.$onu->id.'/configuration-tool/apply',['change_id'=>1,'confirmation_token'=>str_repeat('a',64)]],
            ['GET','/olts/'.$olt->id.'/unconfigured',[]],
            ['POST','/olts/'.$olt->id.'/provision/preview',[]],
            ['POST','/olts/'.$olt->id.'/provision/apply',[]],
            ['POST','/traffic/onus/'.$onu->id.'/live',[]],
            ['POST','/traffic/ports/'.$onu->olt_port_id.'/live',[]],
            ['POST','/olts/'.$olt->id.'/operations',['kind'=>'traffic']],
            ['POST','/olts/'.$olt->id.'/operations',['kind'=>'backup']],
        ];
        foreach($requests as [$method,$url,$data])$this->json($method,$url,$data)->assertUnprocessable();
        $this->assertSame($before,$onu->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('olt_operations',0);
        $this->assertDatabaseCount('onu_configuration_changes',0);
        $this->assertDatabaseCount('provision_plans',0);
    }

    public function test_enqueue_only_accepts_supported_scopes(): void
    {
        $olt=$this->yotc();$service=app(OltOperationService::class);
        foreach(['sync','inventory','names','optics','system'] as $kind){$op=$service->enqueue($olt,$kind);$this->assertSame($kind,$op->kind);}
        $before=OltOperation::count();
        foreach(['traffic','backup','arbitrary'] as $kind){
            try{$service->enqueue($olt,$kind);$this->fail('Unsupported scope was enqueued: '.$kind);}
            catch(HttpExceptionInterface $e){$this->assertSame(422,$e->getStatusCode());}
        }
        $this->assertSame($before,OltOperation::count());
    }

    public function test_scheduler_keeps_five_minute_floor_and_never_reads_zte_traffic_coverage(): void
    {
        $olt=$this->yotc();Olt::where('id','!=',$olt->id)->update(['monitoring_enabled'=>false]);
        config(['olt.state_interval'=>1,'olt.names_interval'=>1,'olt.optical_interval'=>1,'olt.system_interval'=>1,'olt.traffic_interval'=>1,'olt.backup_interval'=>1]);
        $stamp=now()->subSeconds(180);
        $olt->update(['state_observed_at'=>$stamp,'names_observed_at'=>$stamp,'optical_observed_at'=>$stamp,'system_observed_at'=>$stamp,'traffic_observed_at'=>null,'last_backup_at'=>null]);
        $this->mock(ZteTrafficCollectorService::class)->shouldNotReceive('coverage','poll');
        $service=app(OltOperationService::class);$this->assertSame(0,$service->tick());
        $this->assertDatabaseCount('olt_operations',0);
        $this->travel(121)->seconds();$this->assertSame(1,$service->tick());
        $this->assertContains(OltOperation::firstOrFail()->kind,['inventory','names','optics','system']);
        $this->assertDatabaseMissing('olt_operations',['kind'=>'traffic']);$this->assertDatabaseMissing('olt_operations',['kind'=>'backup']);
    }

    public function test_local_tags_and_stored_detail_remain_available(): void
    {
        $olt=$this->yotc();$onu=$olt->onus()->current()->firstOrFail();
        $tag=Tag::create(['tenant_id'=>$olt->tenant_id,'tag_name'=>'EPON','color_hex'=>'#00aabb']);
        $this->actingAs($this->owner())->putJson('/onus/'.$onu->id.'/tags',['tag_ids'=>[$tag->id]])->assertOk();
        $this->assertSame([$tag->id],$onu->tags()->pluck('tags.id')->all());
        $this->getJson('/onus/'.$onu->id.'/detail')->assertOk();
        $this->getJson('/olts/'.$olt->id.'/names')->assertOk();
    }
}
