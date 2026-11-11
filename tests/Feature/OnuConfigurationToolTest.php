<?php
namespace Tests\Feature;
use App\Models\{Onu,User,OnuConfigurationChange};
use App\Services\Olt\{ZteTelnetClient,OnuToolParser,OnuToolService,OltConnectionException};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Queue};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnuConfigurationToolTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);Queue::fake();Cache::flush();}
    private function owner():User{return User::where('email','isp_owner@bima.test')->firstOrFail();}
    private function onu():Onu{$o=Onu::where('tenant_id',$this->owner()->tenant_id)->with(['olt','port'])->firstOrFail();$o->olt->update(['connection_mode'=>'real']);$o->port->update(['observation_source'=>'real']);$o->update(['observation_source'=>'real']);return $o;}
    private function fixture(Onu $o,string $name):string{return str_replace('gpon-onu_1/1/1:1',app(\App\Services\Olt\ZteOnuDetailService::class)->interface($o),file_get_contents(__DIR__.'/../Fixtures/onu-tool-'.$name.'.txt'));}
    private function peer(Onu $o,?string $afterIf=null,?string $afterMng=null,bool $reject=false,?string $sn=null):\ArrayObject{
        $calls=new \ArrayObject();$written=false;$beforeIf=$this->fixture($o,'interface');$beforeMng=$this->fixture($o,'management');
        $mock=\Mockery::mock(ZteTelnetClient::class);$mock->shouldReceive('connect','close','addSecrets')->andReturnNull();
        $mock->shouldReceive('command')->andReturnUsing(function($command)use($o,$calls,&$written,$beforeIf,$beforeMng,$afterIf,$afterMng,$reject,$sn){
            $calls[]=$command;
            if(str_starts_with($command,'show gpon onu detail-info'))return 'Serial number: '.($sn??$o->sn);
            if(str_starts_with($command,'show running-config interface'))return $written?($afterIf??$beforeIf):$beforeIf;
            if(str_starts_with($command,'show onu running config'))return $written?($afterMng??$beforeMng):$beforeMng;
            if($command==='show gpon profile tcont')return "Profile name :UP-1M\nProfile name :UP-2M";
            if($command==='show gpon profile traffic')return "Profile name :DOWN-1M\nProfile name :DOWN-2M";
            if($command==='show gpon onu profile vlan')return "Profile name: STARZONE\nCVLAN: 97";
            if($command==='show gpon onu profile ip')return "Profile name: DATA-UTAMA\nGateway: 172.29.5.1";
            if($reject&&str_starts_with($command,'tcont 2 '))throw new OltConnectionException('Rejected','command_rejected');
            if($command==='end')$written=true;
            return '';
        });$this->app->instance(ZteTelnetClient::class,$mock);return $calls;
    }
    private function preview(Onu $o,array $input){return $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/configuration-tool/preview',$input);}

    public function test_backup_restore_is_previewed_then_verified_for_selected_onu_only():void{
        $o=$this->onu();$interface=app(\App\Services\Olt\ZteOnuDetailService::class)->interface($o);
        $text="interface {$o->port->interface_name}\n onu {$o->onu_index} type F609 sn {$o->sn}\n!\ninterface $interface\n tcont 1 profile UP-1M\n!\npon-onu-mng $interface\n!\n";
        $backup=\App\Models\OltBackup::create(['tenant_id'=>$o->tenant_id,'olt_id'=>$o->olt_id,'configuration'=>$text,'bytes'=>strlen($text),'checksum'=>hash('sha256',$text),'created_at'=>now()]);
        $calls=$this->peer($o);$plan=$this->actingAs($this->owner())->postJson('/backups/'.$backup->id.'/restore-preview',['onu_id'=>$o->id])->assertOk()->json();
        foreach($calls as$command)$this->assertStringStartsWith('show ',$command);
        $this->assertStringNotContainsString('no onu',implode("\n",$plan['commands']));
        $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertOk();
        $this->assertSame('applied',OnuConfigurationChange::first()->status);
        $backup->update(['configuration'=>str_replace($o->sn,'WRONGSN',$text)]);
        $this->postJson('/backups/'.$backup->id.'/restore-preview',['onu_id'=>$o->id])->assertConflict();
    }

    public function test_real_config_parser_handles_seven_sections_wrapping_and_keeps_secrets_out_of_public_state():void{
        $o=$this->onu();$p=new OnuToolParser;$s=$p->parse($this->fixture($o,'interface'),$this->fixture($o,'management'));
        $this->assertSame('UP-1M',$s['tcont'][0]['profile']);$this->assertSame('DOWN-1M',$s['gem'][0]['profile']);$this->assertCount(1,$s['service']);$this->assertSame('Internet pelanggan',$s['service'][0]['description']);
        $this->assertSame('hybrid',$s['ports'][1]['mode']);$this->assertSame('other',$s['ports'][2]['type']);$this->assertSame('iphost',$s['onu_service'][0]['uni_type']);$this->assertSame('STARZONE',$s['wan'][0]['vlan_profile']);$this->assertSame('tool-secret-only',$s['wan'][0]['password']);$this->assertSame('unlock',$s['tr069'][0]['state']);
        $public=$p->publicState($s);$this->assertTrue($public['wan'][0]['has_password']);$this->assertArrayNotHasKey('password',$public['wan'][0]);$this->assertStringNotContainsString('acs-secret-only',json_encode($public));
    }
    public function test_read_and_preview_are_read_only_and_snapshots_and_plans_are_encrypted():void{
        $o=$this->onu();$calls=$this->peer($o);
        $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/configuration-tool/refresh')->assertOk()->assertJsonPath('snapshot.state.tcont.0.profile','UP-1M')->assertDontSee('tool-secret-only')->assertDontSee('acs-secret-only');
        $plan=$this->preview($o,['kind'=>'wan','action'=>'update','id'=>1,'mode'=>'pppoe','username'=>'new-user','password'=>'','vlan_profile'=>'STARZONE','host'=>1,'ping'=>false])->assertOk()->assertDontSee('tool-secret-only')->json();
        $this->assertContains('wan-ip 1 mode pppoe username new-user password [REDACTED] vlan-profile STARZONE host 1',$plan['commands']);
        foreach($calls as$command)$this->assertStringStartsWith('show ',$command);
        $this->assertStringNotContainsString('tool-secret-only',DB::table('onu_configuration_changes')->first()->payload);$this->assertStringNotContainsString('acs-secret-only',DB::table('onu_detail_snapshots')->first()->payload);
        $this->getJson('/onus/'.$o->id.'/configuration-tool')->assertOk()->assertDontSee('tool-secret-only');
    }
    public static function changes():array{return [
        'tcont'=>[['kind'=>'tcont','action'=>'create','id'=>2,'name'=>'tcont2','profile'=>'UP-2M'],'interface','tcont 2 name tcont2 profile UP-2M'],
        'gem'=>[['kind'=>'gem','action'=>'create','id'=>2,'name'=>'internet2','tcont_id'=>1,'traffic_type'=>'ethernet','profile'=>'DOWN-2M'],'interface',"gemport 2 name internet2 tcont 1\ngemport 2 traffic-limit downstream DOWN-2M"],
        'service'=>[['kind'=>'service','action'=>'create','id'=>2,'vlan'=>100,'gem_id'=>1,'description'=>'Business'],'interface',"service-port 2 vport 1 user-vlan 100 vlan 100\nservice-port 2 description Business"],
        'ports'=>[['kind'=>'ports','action'=>'create','port_type'=>'wlan','port_id'=>2,'mode'=>'hybrid','vlan'=>100,'priority'=>5],'management','vlan port wifi_0/2 mode hybrid def-vlan 100 def-pri 5'],
        'ports trunk'=>[['kind'=>'ports','action'=>'create','port_type'=>'ethernet','port_id'=>2,'mode'=>'trunk','vlan'=>100,'priority'=>0],'management',"vlan port eth_0/2 mode trunk\nvlan port eth_0/2 vlan 100"],
        'ports transparent'=>[['kind'=>'ports','action'=>'create','port_type'=>'ethernet','port_id'=>2,'mode'=>'transparent','priority'=>0],'management','vlan port eth_0/2 mode transparent'],
        'onu service'=>[['kind'=>'onu_service','action'=>'create','name'=>'data2','gem_id'=>1,'mapping_type'=>'vlan','vlan'=>100,'priority'=>3,'uni_type'=>'iphost','uni'=>'2'],'management','service data2 gemport 1 iphost 2 cos 3 vlan 100'],
        'wan static'=>[['kind'=>'wan','action'=>'create','id'=>2,'mode'=>'static','ip'=>'172.29.5.20','mask'=>'255.255.255.0','ip_profile'=>'DATA-UTAMA','vlan_profile'=>'STARZONE','host'=>2,'ping'=>true],'management',"wan-ip 2 mode static ip-profile DATA-UTAMA ip-address 172.29.5.20 mask 255.255.255.0 vlan-profile STARZONE host 2\nwan-ip 2 ping-response enable traceroute-response disable"],
        'wan dhcp'=>[['kind'=>'wan','action'=>'create','id'=>2,'mode'=>'dhcp','vlan_profile'=>'STARZONE','host'=>2,'ping'=>false],'management',"wan-ip 2 mode dhcp vlan-profile STARZONE host 2\nwan-ip 2 ping-response disable traceroute-response disable"],
        'remote'=>[['kind'=>'remote','action'=>'create','id'=>212,'state'=>'disable','ingress'=>'lan','protocol'=>'https'],'management','security-mgmt 212 state disable mode forward ingress-type lan protocol https'],
        'tr069'=>[['kind'=>'tr069','action'=>'create','id'=>2,'state'=>'unlock','acs_url'=>'https://acs.example.com/new','auth'=>'basic','username'=>'acs2','password'=>'new-test-secret','vlan_mode'=>'tag','vlan'=>100,'priority'=>1],'management',"tr069-mgmt 2 tag pri 1 vlan 100\ntr069-mgmt 2 acs https://acs.example.com/new validate basic username acs2 password new-test-secret\ntr069-mgmt 2 state unlock"],
    ];}
    #[DataProvider('changes')]
    public function test_each_tab_executes_in_correct_context_and_verifies_readback(array $input,string $context,string $added):void{
        $o=$this->onu();$before=$this->fixture($o,$context);$after=$before."\n".$added;$calls=$this->peer($o,$context==='interface'?$after:null,$context==='management'?$after:null);
        $plan=$this->preview($o,$input)->assertOk()->json();$beforeCalls=count($calls);
        $real=app(\App\Services\Olt\RealOltOperations::class);
        $real->monitoring($o->olt,fn()=>$real->readOnly($o->olt,fn()=>
            $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertOk()->assertJsonPath('message','Perubahan terverifikasi dari OLT.')
        ));
        $writes=array_values(array_filter(array_slice($calls->getArrayCopy(),$beforeCalls),fn($command)=>!str_starts_with($command,'show ')));
        $i=app(\App\Services\Olt\ZteOnuDetailService::class)->interface($o);$this->assertSame(['configure terminal',($context==='interface'?'interface ':'pon-onu-mng ').$i,...explode("\n",$added),'end'],$writes);
        $this->assertSame('applied',OnuConfigurationChange::first()->status);$count=count($calls);
        $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertConflict();$this->assertCount($count,$calls);
    }
    public function test_stale_plan_and_identity_mismatch_never_execute_writes():void{
        $o=$this->onu();$calls=$this->peer($o);$plan=$this->preview($o,['kind'=>'tcont','action'=>'create','id'=>2,'profile'=>'UP-2M'])->assertOk()->json();
        $change=OnuConfigurationChange::first();$payload=$change->payload;$payload['fingerprint']='stale';$change->update(['payload'=>$payload]);
        $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertConflict();$this->assertNotContains('configure terminal',$calls->getArrayCopy());
        $this->assertSame('failed',$change->fresh()->status);
    }
    public function test_partial_write_is_uncertain_and_cannot_be_replayed():void{
        $o=$this->onu();$this->peer($o,reject:true);$plan=$this->preview($o,['kind'=>'tcont','action'=>'create','id'=>2,'profile'=>'UP-2M'])->assertOk()->json();
        $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertStatus(502)->assertJsonPath('reason','configuration_uncertain');$this->assertSame('uncertain',OnuConfigurationChange::first()->status);
    }
    public function test_dependencies_duplicate_ids_injection_and_vlan_reserved_value_are_rejected():void{
        $o=$this->onu();$calls=$this->peer($o);
        foreach([['kind'=>'tcont','action'=>'delete','id'=>'1'],['kind'=>'gem','action'=>'delete','id'=>1],['kind'=>'tcont','action'=>'create','id'=>1,'profile'=>'UP-1M'],['kind'=>'tcont','action'=>'create','id'=>2,'profile'=>'not-present']]as$data)$this->preview($o,$data)->assertUnprocessable();
        $count=count($calls);$this->preview($o,['kind'=>'tcont','action'=>'create','id'=>2,'profile'=>"UP-1M\nend\nreboot"])->assertUnprocessable();$this->preview($o,['kind'=>'service','action'=>'create','id'=>2,'vlan'=>4095,'gem_id'=>1])->assertUnprocessable();$this->assertCount($count,$calls);
        $this->assertNotContains('configure terminal',$calls->getArrayCopy());
    }
    public function test_roles_tenant_isolation_and_read_busy_do_not_touch_device():void{
        $o=$this->onu();$mock=\Mockery::mock(ZteTelnetClient::class);$mock->shouldNotReceive('connect');$this->app->instance(ZteTelnetClient::class,$mock);
        $this->actingAs(User::where('role','cs')->first())->postJson('/onus/'.$o->id.'/configuration-tool/preview',['kind'=>'tcont','action'=>'delete','id'=>1])->assertForbidden();
        $this->actingAs(User::where('email','owner@nusantara.test')->first())->getJson('/onus/'.$o->id.'/configuration-tool')->assertNotFound();
        $lock=Cache::lock('nms:olt-read:'.$o->tenant_id.':'.$o->olt_id,30);$lock->get();try{$this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/configuration-tool/refresh')->assertStatus(202)->assertJsonPath('skipped',true);}finally{$lock->release();}
    }
    public function test_actual_telnet_client_applies_and_reads_back_against_loopback_olt():void{
        $o=$this->onu();$i=app(\App\Services\Olt\ZteOnuDetailService::class)->interface($o);
        $server=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('tests/Fixtures/zte-onu-tool-server.php'),$i,$o->sn]);$server->start();
        try{$server->waitUntil(fn()=>str_contains($server->getOutput(),'"port":'));$port=json_decode(explode("\n",$server->getOutput())[0],true)['port'];
            $o->olt->update(['ip_address'=>'127.0.0.1','telnet_port'=>$port,'telnet_user'=>'admin','telnet_pass_enc'=>'test-secret','cli_transport'=>'telnet']);
            $plan=$this->preview($o,['kind'=>'tcont','action'=>'create','id'=>2,'name'=>'tcont2','profile'=>'UP-2M'])->assertOk()->json();
            $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertOk()->assertJsonPath('snapshot.state.tcont.1.profile','UP-2M');
        }finally{$server->stop(0);}
    }
    public function test_expired_plan_invalid_token_and_changed_connection_prevent_device_io():void{
        $o=$this->onu();$calls=$this->peer($o);$plan=$this->preview($o,['kind'=>'tcont','action'=>'create','id'=>2,'profile'=>'UP-2M'])->assertOk()->json();$count=count($calls);
        $url='/onus/'.$o->id.'/configuration-tool/apply';$body=['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']];
        $this->postJson($url,array_replace($body,['confirmation_token'=>str_repeat('0',64)]))->assertConflict();
        $o->olt->update(['telnet_port'=>2323]);$this->postJson($url,$body)->assertConflict();$o->olt->update(['telnet_port'=>23]);
        OnuConfigurationChange::first()->update(['expires_at'=>now()->subMinute()]);$this->postJson($url,$body)->assertConflict();$this->assertCount($count,$calls);
    }
    public function test_eight_tcont_limit_and_no_false_success_on_missing_readback():void{
        $o=$this->onu();$calls=$this->peer($o);$plan=$this->preview($o,['kind'=>'tcont','action'=>'create','id'=>2,'profile'=>'UP-2M'])->assertOk()->json();
        $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertStatus(502)->assertJsonPath('reason','configuration_uncertain');
        $state=(new OnuToolParser)->parse($this->fixture($o,'interface'),$this->fixture($o,'management'));$state['tcont']=array_map(fn($id)=>['id'=>$id],range(1,8));
        try{app(OnuToolService::class)->prepare(['kind'=>'tcont','action'=>'create','id'=>9,'profile'=>'UP-2M'],$state,['tcont'=>[['name'=>'UP-2M']]],'gpon-onu_1/1/1:1');$this->fail('Ninth T-CONT must be rejected.');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('id',$e->errors());}
    }
    public function test_delete_wan_and_service_readback_removes_only_the_selected_resource():void{
        $o=$this->onu();$after=preg_replace('/^\h*wan-ip 1 .*\n?/m','',$this->fixture($o,'management'));$this->peer($o,afterMng:$after);
        $plan=$this->preview($o,['kind'=>'wan','action'=>'delete','id'=>1])->assertOk()->json();
        $this->postJson('/onus/'.$o->id.'/configuration-tool/apply',['change_id'=>$plan['change_id'],'confirmation_token'=>$plan['confirmation_token']])->assertOk()->assertJsonPath('snapshot.state.wan',[])->assertJsonCount(1,'snapshot.state.onu_service');
    }
    public function test_wrong_serial_blocks_all_configuration_reads_and_writes():void{
        $o=$this->onu();$calls=$this->peer($o,sn:'ZTEG00000000');$this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/configuration-tool/refresh')->assertStatus(502)->assertJsonPath('reason','identity_changed');
        $this->assertCount(1,$calls);$this->assertSame(0,OnuConfigurationChange::count());
    }
}
