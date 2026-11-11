<?php
namespace Tests\Feature;
use App\Models\{Onu,User,OltBackup,OnuOpticalLog};
use App\Services\{SnmpTrapReceiver,HistoryRetention,OpticalAnalysis};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Crypt,Queue};
use Tests\TestCase;
class OperationalImprovementsTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void {parent::setUp();$this->seed(DatabaseSeeder::class);Cache::flush();Queue::fake();}
    private function owner():User{return User::where('email','isp_owner@bima.test')->firstOrFail();}
    private function packet(string $community='trap-secret'):string {
        $tlv=fn($tag,$value)=>chr($tag).(strlen($value)<128?chr(strlen($value)):chr(129).chr(strlen($value))).$value;
        $binding=$tlv(48,$tlv(6,hex2bin('2b060106030101040100')).$tlv(6,hex2bin('2b0601060301010503')));
        return $tlv(48,$tlv(2,"\1").$tlv(4,$community).$tlv(167,$tlv(2,"\1").$tlv(2,"\0").$tlv(2,"\0").$tlv(48,$binding)));
    }
    public function test_traps_authenticate_encrypt_and_do_not_change_onu_status():void {
        $olt=$this->owner()->tenant->olts()->first();$olt->update(['connection_mode'=>'real','ip_address'=>'127.0.0.9','snmp_community_enc'=>'trap-secret']);
        $before=Onu::where('olt_id',$olt->id)->pluck('phase_state','id')->all();$receiver=app(SnmpTrapReceiver::class);
        $this->assertNull($receiver->accept($this->packet('wrong'),'127.0.0.9'));$this->assertNull($receiver->accept($this->packet(),'127.0.0.8'));
        foreach(['',"\x30\x80",substr($this->packet(),0,-1)] as $packet)$this->assertNull($receiver->accept($packet,'127.0.0.9'));
        $id=$receiver->accept($this->packet(),'127.0.0.9');$this->assertNotNull($id);$event=DB::table('snmp_trap_events')->find($id);
        $this->assertSame($olt->tenant_id,$event->tenant_id);$this->assertSame('1.3.6.1.6.3.1.1.5.3',$event->trap_oid);
        $plain=Crypt::decryptString($event->payload);$this->assertStringNotContainsString('trap-secret',$plain);$this->assertStringNotContainsString('bindings',$event->payload);
        $this->assertSame($before,Onu::where('olt_id',$olt->id)->pluck('phase_state','id')->all());
    }
    public function test_retention_preview_keeps_data_and_apply_preserves_latest_backup():void {
        $olt=$this->owner()->tenant->olts()->first();$base=['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'configuration'=>'test','bytes'=>4,'checksum'=>hash('sha256','test'),'created_at'=>now()->subDays(100)];
        $old=OltBackup::create($base);$latest=OltBackup::create($base);$service=app(HistoryRetention::class);
        $this->assertGreaterThanOrEqual(1,$service->run()['olt_backups']);$this->assertNotNull($old->fresh());$service->run(true);
        $this->assertNull($old->fresh());$this->assertNotNull($latest->fresh());
    }
    public function test_decline_uses_history_and_freshness_not_just_weak_signal():void {
        $owner=$this->owner();$onus=Onu::current()->where('tenant_id',$owner->tenant_id)->take(4)->get();$port=$onus[0]->olt_port_id;
        foreach($onus as$i=>$onu){$onu->update(['olt_port_id'=>$port,'rx_power'=>$i===2?-30:-15,'optical_observed_at'=>$i===3?now()->subHour():now()]);
            OnuOpticalLog::where('onu_id',$onu->id)->delete();for($n=1;$n<=3;$n++)OnuOpticalLog::create(['onu_id'=>$onu->id,'rx_power'=>$i===2?-30:-10,'observation_source'=>$onu->observation_source,'calculated_at'=>now()->subHours($n)]);
        }
        $a=app(OpticalAnalysis::class)->forTenant($owner->tenant_id);$this->assertSame(2,$a['items'][$onus[0]->id]['correlated_count']);$this->assertTrue($a['items'][$onus[0]->id]['degraded']);$this->assertFalse($a['items'][$onus[2]->id]['degraded']);$this->assertFalse($a['items'][$onus[3]->id]['degraded']);
    }
    public function test_directory_pages_do_not_overlap_and_search_is_tenant_scoped():void {
        $this->actingAs($this->owner());$first=$this->getJson('/onus?per_page=2&page=1')->assertOk()->json();$second=$this->getJson('/onus?per_page=2&page=2')->assertOk()->json();
        $this->assertCount(2,$first['data']);$this->assertEmpty(array_intersect(array_column($first['data'],'id'),array_column($second['data'],'id')));
        $this->getJson('/onus?q='.urlencode($first['data'][0]['sn']))->assertOk()->assertJsonPath('data.0.id',$first['data'][0]['id']);
        $this->actingAs(User::where('email','owner@nusantara.test')->first());$this->getJson('/onus?olt_id='.$first['data'][0]['olt_id'])->assertOk()->assertJsonPath('total',0);
        $this->getJson('/dashboard/data?compact=1')->assertOk()->assertJsonCount(0,'onus');
    }
}
