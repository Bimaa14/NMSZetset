<?php
namespace Tests\Feature;
use App\Models\{User,Onu};
use App\Services\Olt\{ZteTrafficParser,ZteTrafficCollectorService,ZteSnmpDriver,ZteTelnetClient,OltConnectionException};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Cache,Queue};
use Tests\TestCase;

class TrafficTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);Queue::fake();Cache::flush();}
    private function owner():User{return User::where('email','isp_owner@bima.test')->firstOrFail();}
    private function onu():Onu{$o=Onu::where('tenant_id',$this->owner()->tenant_id)->with(['olt','port'])->first();$o->olt->update(['connection_mode'=>'real','snmp_enabled'=>false]);$o->port->update(['observation_source'=>'real']);$o->update(['observation_source'=>'real']);return $o;}
    private function trafficOutput():string{return "ONU statistic:\n Input rate : 179650 Bps 1150 pps\n Output rate: 3457472 Bps 2817 pps\nInterface peak rate:\n Input peak rate : 7533263 Bps\nTotal statistic:\n Input:\n Bytes:954269038011 Packets:123\n Output:\n Bytes:10934190179644 Packets:456";}
    public function test_snmp_poll_batches_interfaces_and_reports_missing_rate_baselines():void{
        $o=$this->onu();$olt=$o->olt;$olt->update(['snmp_enabled'=>true]);
        $olt->ports()->update(['observation_source'=>'real']);$olt->onus()->update(['observation_source'=>'real','phase_state'=>'working']);
        $entities=$olt->ports()->current()->get()->concat($olt->onus()->current()->with('port')->get());
        $collector=app(ZteTrafficCollectorService::class);$names=[];foreach($entities as$i=>$entity)$names['.1.3.6.1.2.1.31.1.1.1.1.'.($i+1)]=$collector->interface($entity);
        $snmp=\Mockery::mock(ZteSnmpDriver::class);$snmp->shouldReceive('walk')->once()->andReturn($names);$calls=0;
        $snmp->shouldReceive('get')->andReturnUsing(function($olt,$oids)use(&$calls){$calls++;$this->assertLessThanOrEqual(61,count($oids));return array_map(fn($oid)=>str_ends_with($oid,'.1.3.0')?'5000':'1000',$oids);});$this->app->instance(ZteSnmpDriver::class,$snmp);
        $collector=$this->app->make(ZteTrafficCollectorService::class);$this->assertSame([],$collector->poll($olt));$this->assertSame((int)ceil($entities->count()/20),$calls);
        $this->assertSame($entities->count(),DB::table('onu_traffic_logs')->count()+DB::table('port_traffic_logs')->count());
        $coverage=$collector->coverage($olt);$this->assertSame($entities->count(),$coverage['interfaces']);$this->assertSame(0,$coverage['fresh']);$this->assertFalse($coverage['complete']);
    }
    public function test_real_firmware_bytes_rates_direction_and_peak_exclusion():void{
        $p=new ZteTrafficParser;$d=$p->parse($this->trafficOutput());$this->assertSame(27659776.0,$d['rx_bps']);$this->assertSame(1437200.0,$d['tx_bps']);$this->assertSame('10934190179644',$d['rx_bytes']);
        $d=$p->parse(" Input rate: 8 kbps\n Output rate: 2 Mbps\n Input :\n PassBytes :1000\n Output :\n PassBytes :2000");$this->assertSame(8000.0,$d['tx_bps']);$this->assertSame(2000000.0,$d['rx_bps']);$this->assertSame('1000',$d['tx_bytes']);
        $this->assertNull($p->parse('Input peak rate: 3000 Bps')['tx_bps']);
    }
    public function test_snmp_timeout_falls_back_to_cli_and_cooldown_does_not_delay_next_sample():void{
        $o=$this->onu();$o->olt->update(['snmp_enabled'=>true]);$snmp=\Mockery::mock(ZteSnmpDriver::class);$snmp->shouldReceive('walk')->once()->andThrow(new OltConnectionException('timeout','snmp_failed'));$this->app->instance(ZteSnmpDriver::class,$snmp);
        $cli=\Mockery::mock(ZteTelnetClient::class);$cli->shouldReceive('connect','close')->twice()->andReturnNull();$cli->shouldReceive('command')->twice()->andReturn($this->trafficOutput());$this->app->instance(ZteTelnetClient::class,$cli);
        $this->actingAs($this->owner())->postJson('/traffic/onus/'.$o->id.'/live')->assertOk()->assertJsonPath('sample.source','cli')->assertJsonPath('sample.rx_bps',27659776);
        $port=$o->port;app(ZteTrafficCollectorService::class)->collect($port);$this->assertDatabaseCount('onu_traffic_logs',1);$this->assertDatabaseCount('port_traffic_logs',1);
    }
    public function test_snmp_hc_counters_preferred_and_first_sample_is_not_fake_zero():void{
        $o=$this->onu();$o->olt->update(['snmp_enabled'=>true]);$name=app(ZteTrafficCollectorService::class)->interface($o);
        $snmp=\Mockery::mock(ZteSnmpDriver::class);$snmp->shouldReceive('walk')->once()->andReturn(['.1.3.6.1.2.1.31.1.1.1.1.9'=>$name]);$snmp->shouldReceive('get')->once()->andReturn(['100','200','0','5000']);$this->app->instance(ZteSnmpDriver::class,$snmp);
        $this->actingAs($this->owner())->postJson('/traffic/onus/'.$o->id.'/live')->assertOk()->assertJsonPath('sample.source','snmp')->assertJsonPath('sample.rx_bps',null)->assertJsonPath('sample.rx_bytes','200');
    }
    public function test_history_wib_custom_range_counter_volume_resets_and_tenant_boundary():void{
        $o=$this->onu();$base=['onu_id'=>$o->id,'olt_id'=>$o->olt_id,'tenant_id'=>$o->tenant_id,'source'=>'cli','download_bps'=>800,'upload_bps'=>80,'rx_bps'=>800,'tx_bps'=>80];
        foreach([['2026-10-01 00:00:00','1000','100'],['2026-10-01 00:00:10','2000','200'],['2026-10-01 00:00:20','20','2'],['2026-10-01 01:00:00','999999','999']] as [$at,$rx,$tx])DB::table('onu_traffic_logs')->insert($base+['recorded_at'=>$at,'observed_at'=>$at,'rx_bytes'=>$rx,'tx_bytes'=>$tx]);
        $query=http_build_query(['from'=>'2026-09-30T17:00:00+00:00','to'=>'2026-10-01T17:00:00+00:00']);
        $this->actingAs($this->owner())->getJson('/traffic/onus/'.$o->id.'?'.$query)->assertOk()->assertJsonPath('sample_count',4)->assertJsonPath('statistics.rx.volume_bytes',1000)->assertJsonPath('statistics.rx.covered_seconds',10)->assertJsonPath('statistics.rx.average',800);
        $this->actingAs(User::where('email','owner@nusantara.test')->first())->getJson('/traffic/onus/'.$o->id)->assertNotFound();
        $this->postJson('/traffic/onus/'.$o->id.'/live')->assertNotFound();
    }
    public function test_unsupported_cli_does_not_write_zero_and_invalid_dates_rejected():void{
        $o=$this->onu();$cli=\Mockery::mock(ZteTelnetClient::class);$cli->shouldReceive('connect','close')->andReturnNull();$cli->shouldReceive('command')->andReturn('No statistics available');$this->app->instance(ZteTelnetClient::class,$cli);
        $this->actingAs($this->owner())->postJson('/traffic/onus/'.$o->id.'/live')->assertStatus(502);$this->assertDatabaseCount('onu_traffic_logs',0);
        $this->getJson('/traffic/onus/'.$o->id.'?minutes=-1')->assertUnprocessable();
    }
    public function test_monitor_schedules_cli_traffic_when_snmp_is_disabled():void{
        $o=$this->onu();$o->olt->update(['monitoring_enabled'=>true,'state_observed_at'=>now(),'names_observed_at'=>now(),'optical_observed_at'=>now(),'system_observed_at'=>now(),'last_backup_at'=>now(),'traffic_observed_at'=>null]);
        app(\App\Services\Olt\OltOperationService::class)->tick();
        $this->assertDatabaseHas('olt_operations',['olt_id'=>$o->olt_id,'kind'=>'traffic','status'=>'queued']);
    }
    public function test_counter_only_cli_uses_delta_and_does_not_mix_with_snmp_baseline():void{
        $o=$this->onu();$c=app(ZteTrafficCollectorService::class);
        $a=$c->persist($o,['rx_bps'=>null,'tx_bps'=>null,'rx_bytes'=>'18000000000000000000','tx_bytes'=>'1000'],'cli');$this->assertNull($a['rx_bps']);
        usleep(600000);
        $b=$c->persist($o,['rx_bps'=>null,'tx_bps'=>null,'rx_bytes'=>'18000000000000000600','tx_bytes'=>'1300'],'cli');
        $this->assertGreaterThan(0,$b['rx_bps']);$this->assertEqualsWithDelta($b['rx_bps']/2,$b['tx_bps'],0.001);
        $c=$c->persist($o,['rx_bps'=>null,'tx_bps'=>null,'rx_bytes'=>'18000000000000001200','tx_bytes'=>'1600'],'snmp',100,'0');$this->assertNull($c['rx_bps']);
    }

    public function test_live_read_uses_a_separate_session_while_background_holds_operational_lock():void{
        $o=$this->onu();$lock=Cache::lock('nms:olt:'.$o->tenant_id.':'.$o->olt_id,30);$this->assertTrue($lock->get());
        $cli=\Mockery::mock(ZteTelnetClient::class);$cli->shouldReceive('connect','close')->once()->andReturnNull();$cli->shouldReceive('command')->once()->andReturn($this->trafficOutput());$this->app->instance(ZteTelnetClient::class,$cli);
        try{$this->actingAs($this->owner())->postJson('/traffic/onus/'.$o->id.'/live')->assertOk()->assertJsonPath('sample.rx_bps',27659776);}
        finally{$lock->release();}
        $this->assertDatabaseCount('onu_traffic_logs',1);
    }

    public function test_busy_read_skips_without_device_io_or_overwriting_saved_sample():void{
        $o=$this->onu();app(ZteTrafficCollectorService::class)->persist($o,['rx_bps'=>800,'tx_bps'=>80,'rx_bytes'=>null,'tx_bytes'=>null],'cli');
        $cli=\Mockery::mock(ZteTelnetClient::class);$cli->shouldNotReceive('connect');$this->app->instance(ZteTelnetClient::class,$cli);
        $lock=Cache::lock('nms:olt-read:'.$o->tenant_id.':'.$o->olt_id,30);$this->assertTrue($lock->get());
        try{$this->actingAs($this->owner())->postJson('/traffic/onus/'.$o->id.'/live')->assertStatus(202)->assertJsonPath('skipped',true)->assertJsonPath('reason','read_busy')->assertHeader('Cache-Control','no-store, private');}
        finally{$lock->release();}
        $this->assertDatabaseCount('onu_traffic_logs',1);$this->assertEquals(800,$o->fresh()->download_bps);
    }

    public function test_history_reports_peak_average_minimum_and_current_separately():void{
        $o=$this->onu();$collector=app(ZteTrafficCollectorService::class);
        $this->travelTo(now()->startOfMinute());
        foreach([800,1600,400] as $rate){$collector->persist($o,['rx_bps'=>$rate,'tx_bps'=>$rate/10,'rx_bytes'=>null,'tx_bytes'=>null],'cli');$this->travel(10)->seconds();}
        $this->actingAs($this->owner())->getJson('/traffic/onus/'.$o->id.'?minutes=5')->assertOk()->assertJsonPath('statistics.rx.current',400)->assertJsonPath('statistics.rx.peak',1600)->assertJsonPath('statistics.rx.minimum',400)->assertJsonPath('statistics.rx.average',1100)->assertJsonPath('statistics.tx.minimum',40);
    }

    public function test_live_and_background_counter_writes_are_serialized_per_interface():void{
        $o=$this->onu();$collector=app(ZteTrafficCollectorService::class);$data=['rx_bps'=>800,'tx_bps'=>80,'rx_bytes'=>'1000','tx_bytes'=>'100'];
        $lock=Cache::lock('nms:traffic-sample:'.$o->tenant_id.':onus:'.$o->id,30);$this->assertTrue($lock->get());
        try{
            try{$collector->persist($o,$data,'cli');$this->fail('Concurrent sample should be skipped.');}
            catch(OltConnectionException $e){$this->assertSame('read_busy',$e->reason);}
            $this->assertDatabaseCount('onu_traffic_logs',0);
            $collector->persist($o->port,$data,'cli');$this->assertDatabaseCount('port_traffic_logs',1);
        }finally{$lock->release();}
        $collector->persist($o,$data,'cli');$this->assertDatabaseCount('onu_traffic_logs',1);
    }

    public function test_worker_requeues_when_an_operational_session_holds_the_olt_lock():void{
        $o=$this->onu();$o->olt->update(['monitoring_enabled'=>true]);$op=app(\App\Services\Olt\OltOperationService::class)->enqueue($o->olt,'traffic');
        $lock=Cache::lock('nms:olt:'.$o->tenant_id.':'.$o->olt_id,30);$this->assertTrue($lock->get());
        try{app()->call([new \App\Jobs\PollOlt($op->id),'handle']);}finally{$lock->release();}
        $this->assertSame('queued',$op->fresh()->status);$this->assertNotNull($op->fresh()->active_olt_id);$this->assertNull($op->fresh()->error);
    }

    public function test_long_history_is_sql_aggregated_to_150_points_without_losing_peak_or_uint64_volume():void{
        $o=$this->onu();$this->travelTo(now()->startOfMinute());$start=now()->subHours(2);$rows=[];
        for($i=0;$i<2000;$i++){$at=$start->copy()->addSeconds($i);$counter=(new \phpseclib3\Math\BigInteger('18000000000000000000'))->add(new \phpseclib3\Math\BigInteger((string)($i*100)))->toString();$rows[]=['onu_id'=>$o->id,'tenant_id'=>$o->tenant_id,'olt_id'=>$o->olt_id,'source'=>'cli','recorded_at'=>$at,'observed_at'=>$at,'rx_bps'=>$i===100?80820000:1000000,'tx_bps'=>0,'rx_bytes'=>$counter,'tx_bytes'=>'0'];}
        foreach(array_chunk($rows,100)as$chunk)DB::table('onu_traffic_logs')->insert($chunk);
        foreach([10080,43200]as$minutes){$data=$this->actingAs($this->owner())->getJson('/traffic/onus/'.$o->id.'?minutes='.$minutes)->assertOk()->assertJsonPath('sample_count',2000)->assertJsonPath('statistics.rx.peak',80820000)->assertJsonPath('statistics.rx.volume_bytes',199900)->json();$this->assertLessThanOrEqual(150,count($data['points']));$this->assertGreaterThan(100,count($data['points']));}
    }

    public function test_history_clips_previous_sample_to_requested_boundary_and_handles_empty_range():void{
        $o=$this->onu();$this->travelTo(now()->startOfMinute());$collector=app(ZteTrafficCollectorService::class);
        $collector->persist($o,['rx_bps'=>800,'tx_bps'=>0,'rx_bytes'=>null,'tx_bytes'=>null],'cli');$this->travel(10)->seconds();
        $collector->persist($o,['rx_bps'=>800,'tx_bps'=>0,'rx_bytes'=>null,'tx_bytes'=>null],'cli');
        $query=http_build_query(['from'=>now()->subSeconds(5)->toIso8601String(),'to'=>now()->addSecond()->toIso8601String()]);
        $this->actingAs($this->owner())->getJson('/traffic/onus/'.$o->id.'?'.$query)->assertOk()->assertJsonPath('sample_count',1)->assertJsonPath('statistics.rx.volume_bytes',500)->assertJsonPath('statistics.rx.covered_seconds',5);
        $this->travel(31)->days();$this->getJson('/traffic/onus/'.$o->id.'?minutes=5')->assertOk()->assertJsonPath('sample_count',0)->assertJsonPath('points',[])->assertJsonPath('statistics.rx.current',null);
    }

    public function test_presets_keep_full_start_end_and_baseline_does_not_change_measured_statistics():void{
        $o=$this->onu();$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-17 20:23:00','Asia/Jakarta'));
        $base=['onu_id'=>$o->id,'olt_id'=>$o->olt_id,'tenant_id'=>$o->tenant_id,'source'=>'cli'];
        foreach([['2026-10-17 13:53:00',10000000],['2026-10-17 13:53:10',20000000]]as[$at,$rate])DB::table('onu_traffic_logs')->insert($base+['recorded_at'=>$at,'observed_at'=>$at,'rx_bps'=>$rate,'tx_bps'=>1000000]);
        foreach([30,60,360,1440,10080,43200]as$minutes){
            $data=$this->actingAs($this->owner())->getJson('/traffic/onus/'.$o->id.'?minutes='.$minutes)->assertOk()->json();
            $this->assertSame(now()->subMinutes($minutes)->toIso8601String(),$data['from']);$this->assertSame(now()->toIso8601String(),$data['to']);
            if($minutes<1440){$this->assertNull($data['chart_baseline']);continue;}
            $this->assertSame($data['from'],$data['chart_baseline']['from']);$this->assertSame('2026-10-17T13:53:00+07:00',$data['chart_baseline']['to']);
            $this->assertSame(2,$data['sample_count']);$this->assertEquals(10000000,$data['statistics']['rx']['minimum']);$this->assertEquals(15000000,$data['statistics']['rx']['average']);$this->assertCount(2,$data['points']);
        }
        $query=http_build_query(['from'=>'2026-10-17T13:53:05+07:00','to'=>'2026-10-17T20:23:00+07:00']);
        $this->getJson('/traffic/onus/'.$o->id.'?'.$query)->assertOk()->assertJsonPath('chart_baseline',null);
    }

}
