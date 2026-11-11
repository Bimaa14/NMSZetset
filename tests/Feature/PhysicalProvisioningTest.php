<?php
namespace Tests\Feature;
use App\Models\{User,ServiceProfile};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Queue};
use Symfony\Component\Process\Process;
use Tests\TestCase;
class PhysicalProvisioningTest extends TestCase {
    use RefreshDatabase;
    private ?Process $server=null;
    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);Queue::fake();}
    protected function tearDown():void{$this->server?->stop(0);parent::tearDown();}
    private function prepare(string $scenario=''):array{
        $this->server=new Process([PHP_BINARY,base_path('tests/Fixtures/zte-provision-server.php'),$scenario]);$this->server->start();$this->server->waitUntil(fn()=>str_contains($this->server->getOutput(),'"port":'));
        $port=json_decode(explode("\n",$this->server->getOutput())[0],true)['port'];$owner=User::where('email','isp_owner@bima.test')->first();$this->actingAs($owner);$olt=$owner->tenant->olts()->first();$olt->update(['connection_mode'=>'real','ip_address'=>'127.0.0.1','telnet_port'=>$port,'telnet_user'=>'admin','telnet_pass_enc'=>'test-secret']);
        $profile=ServiceProfile::create(['tenant_id'=>$olt->tenant_id,'name'=>'GPON','onu_type'=>'F609','mode'=>$scenario==='reject'?'pppoe':'registration','upstream_profile'=>'UP-1M','downstream_profile'=>'DOWN-1M','wan_profile'=>'INTERNET','vlan_id'=>123]);return [$olt,$profile];
    }
    public function test_real_discovery_preflight_write_and_readback_verification():void{
        [$olt,$profile]=$this->prepare();$this->getJson('/olts/'.$olt->id.'/unconfigured')->assertOk()->assertJsonCount(3,'onus')->assertJsonPath('onus.0.sn','ZTEGC9945478');
        $body=['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>3,'onu_index'=>1,'sn'=>'ZTEGC9945478','name'=>'NEW-REAL'];
        $this->postJson('/olts/'.$olt->id.'/provision/preview',$body)->assertStatus(502)->assertJsonPath('reason','configuration');
        $body['onu_index']=2;$p=$this->postJson('/olts/'.$olt->id.'/provision/preview',$body)->assertOk()->json();$apply=['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token']];
        $this->postJson('/olts/'.$olt->id.'/provision/apply',$apply)->assertOk()->assertJsonPath('plan.status','applied');$this->postJson('/olts/'.$olt->id.'/provision/apply',$apply)->assertConflict();
        $this->assertSame(1,substr_count($this->server->getOutput(),'onu 2 type F609 sn ZTEGC9945478'));$this->assertDatabaseHas('audit_logs',['action'=>'onu.provision.applied']);
    }
    public function test_rejected_pppoe_keeps_partial_result_and_redacts_password():void{
        [$olt,$profile]=$this->prepare('reject');$p=$this->postJson('/olts/'.$olt->id.'/provision/preview',['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>3,'onu_index'=>2,'sn'=>'ZTEGC9945478','name'=>'NEW'])->assertOk()->json();$secret='CustomerSecret456';
        $this->postJson('/olts/'.$olt->id.'/provision/apply',['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token'],'pppoe_user'=>'customer@isp','pppoe_password'=>$secret])->assertStatus(502)->assertJsonPath('plan.status','uncertain')->assertDontSee($secret);
        $this->assertStringNotContainsString($secret,DB::table('provision_plans')->first()->result);$this->assertStringNotContainsString($secret,$this->server->getOutput());
    }
    public function test_verified_registration_survives_queue_failure_and_cannot_be_replayed():void{
        [$olt,$profile]=$this->prepare();
        $p=$this->postJson('/olts/'.$olt->id.'/provision/preview',['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>3,'onu_index'=>2,'sn'=>'ZTEGC9945478','name'=>'NEW'])->assertOk()->json();
        $this->mock(\App\Services\Olt\OltOperationService::class)->shouldReceive('enqueue')->once()->andThrow(new \RuntimeException('queue-secret'));
        $apply=['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token']];
        $this->postJson('/olts/'.$olt->id.'/provision/apply',$apply)->assertOk()->assertJsonPath('plan.status','applied')->assertJsonCount(1,'warnings')->assertDontSee('queue-secret');
        $this->postJson('/olts/'.$olt->id.'/provision/apply',$apply)->assertConflict();
        $this->assertDatabaseHas('provision_plans',['id'=>$p['plan']['id'],'status'=>'applied']);
        $this->assertSame(1,substr_count($this->server->getOutput(),'onu 2 type F609 sn ZTEGC9945478'));
    }
    public function test_verified_registration_survives_audit_failure_and_still_queues_inventory():void{
        [$olt,$profile]=$this->prepare();
        $p=$this->postJson('/olts/'.$olt->id.'/provision/preview',['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>3,'onu_index'=>2,'sn'=>'ZTEGC9945478','name'=>'NEW'])->assertOk()->json();
        $this->mock(\App\Services\AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit-secret'));
        $this->postJson('/olts/'.$olt->id.'/provision/apply',['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token']])->assertOk()->assertJsonPath('plan.status','applied')->assertJsonCount(1,'warnings')->assertDontSee('audit-secret');
        $this->assertDatabaseHas('olt_operations',['olt_id'=>$olt->id,'kind'=>'inventory','status'=>'queued']);
    }
    public function test_busy_lock_preserves_preview_for_retry():void{
        [$olt,$profile]=$this->prepare();$p=$this->postJson('/olts/'.$olt->id.'/provision/preview',['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>3,'onu_index'=>2,'sn'=>'ZTEGC9945478','name'=>'NEW'])->assertOk()->json();
        $apply=['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token']];
        app(\App\Services\Olt\RealOltOperations::class)->locked($olt,fn()=>$this->postJson('/olts/'.$olt->id.'/provision/apply',$apply)->assertConflict()->assertJsonPath('reason','olt_busy'));
        $this->assertDatabaseHas('provision_plans',['id'=>$p['plan']['id'],'status'=>'planned']);
        $this->postJson('/olts/'.$olt->id.'/provision/apply',$apply)->assertOk()->assertJsonPath('plan.status','applied');
    }
    public function test_registration_alone_does_not_verify_bridge_services():void{
        [$olt,$profile]=$this->prepare();$profile->update(['mode'=>'bridge']);
        $p=$this->postJson('/olts/'.$olt->id.'/provision/preview',['profile_id'=>$profile->id,'frame'=>1,'slot'=>1,'port'=>3,'onu_index'=>2,'sn'=>'ZTEGC9945478','name'=>'NEW'])->assertOk()->json();
        $this->postJson('/olts/'.$olt->id.'/provision/apply',['plan_id'=>$p['plan']['id'],'confirmation_token'=>$p['confirmation_token']])->assertStatus(502)->assertJsonPath('plan.status','uncertain');
    }
    public function test_backup_keeps_secrets_encrypted_and_requires_owner_for_download():void{
        [$olt]=$this->prepare();$real=app(\App\Services\Olt\RealOltOperations::class);$real->poll($olt,'backup');
        $backup=\App\Models\OltBackup::firstOrFail();$this->assertStringNotContainsString('test-secret',$backup->getRawOriginal('configuration'));$this->assertStringContainsString('test-secret',$backup->configuration);
        $this->getJson('/olts/'.$olt->id.'/backups')->assertOk()->assertDontSee('test-secret')->assertDontSee('test-community');
        $this->get('/backups/'.$backup->id.'/download')->assertOk()->assertSee('test-secret');
        $this->actingAs(User::where('role','noc')->first())->get('/backups/'.$backup->id.'/download')->assertForbidden();
        $this->actingAs(User::where('email','owner@nusantara.test')->first())->get('/backups/'.$backup->id.'/download')->assertNotFound();
    }
    public function test_real_onu_rename_admin_and_delete_verify_the_device_result():void{
        [$olt]=$this->prepare();$port=$olt->ports()->updateOrCreate(['interface_name'=>'gpon-olt_1/1/3'],['slot_no'=>1,'port_no'=>3,'observation_source'=>'real','is_present'=>true]);
        $onu=\App\Models\Onu::create(['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'olt_port_id'=>$port->id,'onu_index'=>1,'name'=>'EXISTING','sn'=>'ZTEGC0D153C2','phase_state'=>'working','observation_source'=>'real','is_present'=>true,'admin_state'=>'enable']);
        $this->putJson('/onus/'.$onu->id.'/name',['name'=>'NEW-NAME'])->assertOk();$this->assertSame('NEW-NAME',$onu->fresh()->name);
        $this->putJson('/onus/'.$onu->id.'/admin',['enabled'=>false])->assertOk();$this->assertSame('disable',$onu->fresh()->admin_state);$this->assertSame('working',$onu->fresh()->phase_state);
        $this->deleteJson('/onus/'.$onu->id)->assertOk();$this->assertFalse((bool)$onu->fresh()->is_present);$this->deleteJson('/onus/'.$onu->id)->assertNotFound();
    }
}
