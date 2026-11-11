<?php
namespace Tests\Feature;
use App\Models\{User,Onu,OnuDetailSnapshot};
use App\Services\Olt\{ZteTelnetClient,ZteOnuDetailParser,OltConnectionException};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Queue};
use Tests\TestCase;
class OnuDetailTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void {parent::setUp();$this->seed(DatabaseSeeder::class);Queue::fake();}
    private function owner():User{return User::where('email','isp_owner@bima.test')->firstOrFail();}
    private function onu():Onu{$o=Onu::where('tenant_id',$this->owner()->tenant_id)->with(['olt','port'])->first();$o->olt->update(['connection_mode'=>'real']);$o->update(['observation_source'=>'real']);return $o;}
    private function peer(array $outputs):array {
        $calls=new \ArrayObject();$mock=\Mockery::mock(ZteTelnetClient::class);$mock->shouldReceive('connect','close','addSecrets')->andReturnNull();
        $mock->shouldReceive('command')->andReturnUsing(function($command)use($outputs,$calls){$calls[]=$command;foreach($outputs as $prefix=>$output)if(str_starts_with($command,$prefix)){if($output instanceof \Throwable)throw $output;return $output;}throw new OltConnectionException('Unsupported command','command_rejected');});$this->app->instance(ZteTelnetClient::class,$mock);return [$mock,$calls];
    }
    public function test_actual_identity_description_uptime_and_attenuation_are_parsed_without_guesses():void {
        $p=new ZteOnuDetailParser();$d=$p->info("Name: ARFIN-HOME\nSerial number: ZTEGCCA26B50\nType: F609\nPhase state: DyingGasp\nONU Distance: 6053m\nDescription: \$\$\$\$zone_Zone_1_descr__extid_ZTEGOTHER\n1 0000-00-00 00:00:00 0000-00-00 00:00:00\n2 2026-10-15 10:30:00 2026-10-16 11:20:00 DyingGasp", "Equipment ID: F609V3.0\nSoftware version: V3.0", "up Rx :-20.63(dBm) Tx :2.61(dBm)\ndown Tx :4.00(dBm) Rx :-16.40(dBm)");
        $this->assertSame('dying-gasp',$d['phase_state']);$this->assertSame(6053.0,$d['distance_m']);$this->assertSame(-16.4,$d['rx_power']);$this->assertSame(2.61,$d['tx_power']);$this->assertSame(-20.63,$d['olt_rx_power']);$this->assertSame('2026-10-16 11:20:00',$d['last_offline_at']);$this->assertNull($d['description']);$this->assertSame('F609V3.0',$d['reported_type']);
    }
    public function test_transition_states_are_preserved_in_device_details():void {
        foreach(['SyncMib'=>'sync-mib','Logging'=>'logging','Auth'=>'auth'] as $raw=>$expected)$this->assertSame($expected,(new ZteOnuDetailParser)->info('Phase state: '.$raw)['phase_state']);
    }
    public function test_reboot_lock_rejection_is_distinct_and_never_calls_the_device():void {
        $o=$this->onu();$driver=\Mockery::mock(\App\Services\Olt\ZteTelnetDriver::class);
        $driver->shouldReceive('reboot')->once()->with(\Mockery::on(fn($onu)=>$onu->id===$o->id))->andReturn('Reboot accepted');
        $this->app->instance(\App\Services\Olt\ZteTelnetDriver::class,$driver);
        $lock=\Illuminate\Support\Facades\Cache::lock('nms:olt-exclusive:'.$o->tenant_id.':'.$o->olt_id,30);$this->assertTrue($lock->get());
        try{
            $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/reboot')->assertConflict()->assertJsonPath('reason','olt_busy');
            $this->assertNull($o->fresh()->reboot_requested_at);
        }finally{$lock->release();}
        $real=app(\App\Services\Olt\RealOltOperations::class);
        $real->monitoring($o->olt,fn()=>$real->readOnly($o->olt,fn()=>
            $this->postJson('/onus/'.$o->id.'/reboot')->assertOk()
        ));
        $this->assertNotNull($o->fresh()->reboot_requested_at);
    }
    public function test_detail_read_can_run_during_polling_and_busy_read_preserves_snapshot():void {
        $o=$this->onu();$this->peer(['show gpon onu detail-info'=>"Serial number: {$o->sn}\nPhase state: SyncMib",'show gpon remote-onu interface eth'=>"Port: eth_0/1\nAdmin state: enable"]);
        $lock=\Illuminate\Support\Facades\Cache::lock('nms:olt:'.$o->tenant_id.':'.$o->olt_id,30);$this->assertTrue($lock->get());
        try{$this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/detail/refresh',['section'=>'ethernet'])->assertOk()->assertJsonPath('data.items.0.id',1);}
        finally{$lock->release();}
        $snapshot=OnuDetailSnapshot::first()->getRawOriginal('payload');
        $read=\Illuminate\Support\Facades\Cache::lock('nms:olt-read:'.$o->tenant_id.':'.$o->olt_id,30);$this->assertTrue($read->get());
        try{$this->postJson('/onus/'.$o->id.'/detail/refresh',['section'=>'ethernet'])->assertStatus(202)->assertJsonPath('skipped',true);}
        finally{$read->release();}
        $this->assertSame($snapshot,OnuDetailSnapshot::first()->getRawOriginal('payload'));
    }
    public function test_wrapped_actual_wan_config_and_ethernet_wifi_records():void {
        $p=new ZteOnuDetailParser();$rows=$p->wan("WAN: 1\nIP address: 172.16.1.2\nStatus: connected", "wan-ip 1 mode pppoe username testuser password test-secret vlan-profile MGMT-O\nNU host 1");$this->assertSame('MGMT-ONU',$rows[0]['vlan_profile']);$this->assertSame('172.16.1.2',$rows[0]['ip']);$this->assertSame('test-secret',$rows[0]['password']);
        $ports=$p->records("Port: eth_0/1\nAdmin state: enable\nOperational state: active\nActual speed: 1000 Mbps\nPort: eth_0/2\nAdmin state: disable\nOperational state: down",'ethernet');$this->assertCount(2,$ports);$this->assertSame('1000 Mbps',$ports[0]['speed']);
        $wifi=$p->wifi('',"ssid ctrl wifi_0/1 name HOME admin enable\nssid auth wifi_0/1 wpa2-psk encrypt aes key test-pass");$this->assertSame('HOME',$wifi[0]['name']);$this->assertSame('test-pass',$wifi[0]['password']);$this->assertStringNotContainsString('test-pass',$p->sanitize('ssid auth wifi_0/1 wpa2-psk encrypt aes key test-pass'));
    }
    public function test_live_wan_encrypted_at_rest_and_password_reveal_requires_operational_role():void {
        $o=$this->onu();$this->peer(['show gpon onu detail-info'=>"Serial number: {$o->sn}\nName: HOME",'show onu running config'=>'wan-ip 1 mode pppoe username testuser password only-for-test vlan-profile MGMT host 1','show gpon remote-onu wan-ip'=>"WAN: 1\nIP address: 172.16.1.2\nStatus: connected"]);
        $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/detail/refresh',['section'=>'wan'])->assertOk()->assertJsonPath('data.items.0.has_password',true)->assertDontSee('only-for-test');
        $raw=DB::table('onu_detail_snapshots')->first()->payload;$this->assertStringNotContainsString('only-for-test',$raw);
        $this->getJson('/onus/'.$o->id.'/detail')->assertOk()->assertDontSee('only-for-test');
        $this->postJson('/onus/'.$o->id.'/detail/secret',['section'=>'wan','item_id'=>1])->assertOk()->assertJsonPath('password','only-for-test')->assertHeader('Cache-Control','no-store, private');
        $this->actingAs(User::where('role','cs')->first())->postJson('/onus/'.$o->id.'/detail/secret',['section'=>'wan','item_id'=>1])->assertForbidden();
    }
    public function test_identity_mismatch_never_publishes_detail_and_tenant_isolation():void {
        $o=$this->onu();$this->peer(['show gpon onu detail-info'=>'Serial number: ZTEGWRONG']);$this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/detail/refresh',['section'=>'info'])->assertStatus(502);$this->assertSame(0,OnuDetailSnapshot::count());
        $this->actingAs(User::where('email','owner@nusantara.test')->first())->getJson('/onus/'.$o->id.'/detail')->assertNotFound();
    }
    public function test_unsupported_read_preserves_old_snapshot_and_marks_it_stale():void {
        $o=$this->onu();OnuDetailSnapshot::create(['onu_id'=>$o->id,'section'=>'ethernet','source'=>'real','payload'=>['data'=>['items'=>[['id'=>1,'admin'=>'enable']]]],'observed_at'=>now()->subHour()]);$this->peer(['show gpon onu detail-info'=>"Serial number: {$o->sn}"]);
        $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/detail/refresh',['section'=>'ethernet'])->assertOk()->assertJsonPath('stale',true)->assertJsonPath('data.items.0.id',1);
    }
    public function test_configuration_rejects_unconfirmed_action_injection_and_never_retries_rejected_write():void {
        $o=$this->onu();[, $calls]=$this->peer(['show gpon onu detail-info'=>"Serial number: {$o->sn}",'configure terminal'=>'','pon-onu-mng'=>'','interface eth eth_0/1 state lock'=>new OltConnectionException('Unsupported parameter','command_rejected')]);
        $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/detail/configure',['kind'=>'factory'])->assertUnprocessable();
        $this->postJson('/onus/'.$o->id.'/detail/configure',['kind'=>'wifi','name'=>"evil\nend",'confirmed'=>true])->assertUnprocessable();
        $this->postJson('/onus/'.$o->id.'/detail/configure',['kind'=>'ethernet','id'=>1,'enabled'=>false,'confirmed'=>true])->assertStatus(502);
        $this->assertSame(1,count(array_filter((array)$calls,fn($c)=>$c==='interface eth eth_0/1 state lock')));$this->assertNotContains('interface eth eth_0/1 state lock ?',(array)$calls);
    }
    public function test_v210_device_outputs_map_current_ips_ports_and_ssids():void {
        $p=new ZteOnuDetailParser();$wan=$p->wan("WAN index: 1\nMode: PPPoE\nCVLAN: 88\nCurrent IP: 172.16.158.13\nCurrent mask: 255.255.255.255\nHost name: omci_ipv4_pppoe_1",'');$this->assertSame('172.16.158.13',$wan[0]['ip']);$this->assertSame(88.0,$wan[0]['vlan_id']);
        $ports=$p->records("Interface: eth_0/4\nSpeed status: full-100\nOperate status: enable\nAdmin status: unlock\nSpeed config: auto",'ethernet');$this->assertSame('enable',$ports[0]['operational']);$this->assertSame('auto',$ports[0]['auto_negotiation']);
        $wifi=$p->wifi("Interface: wifi_0/1\nName:\nHide: disable\nUser isolation: enable\nMax users: 32\nCurrent users: 0\nAuthentication algorithm: WPA-PSK and WPA2-PSK\nEncryption: TKIP and AES\nKey: use ONU default",'',"Interface: wifi_0/1\nAdmin status: unlock\nOperate status: enable");$this->assertCount(1,$wifi);$this->assertNull($wifi[0]['password']);$this->assertSame('unlock',$wifi[0]['admin']);$this->assertSame('WPA-PSK and WPA2-PSK',$wifi[0]['security']);$this->assertSame('Terlihat',$wifi[0]['visibility']);
    }
    public function test_wifi_write_uses_v210_syntax_and_verifies_both_name_and_key():void {
        $o=$this->onu();[, $calls]=$this->peer(['show gpon onu detail-info'=>"Serial number: {$o->sn}",'configure terminal'=>'','pon-onu-mng'=>'','ssid ctrl'=>'','ssid auth wpa'=>'','end'=>'','show onu running config'=>"ssid ctrl wifi_0/1 name NEW-WIFI\nssid auth wpa wifi_0/1 wpa-wpa2-psk encrypt aes key test-secret-123"]);
        $this->actingAs($this->owner())->postJson('/onus/'.$o->id.'/detail/configure',['kind'=>'wifi','id'=>1,'name'=>'NEW-WIFI','password'=>'test-secret-123','confirmed'=>true])->assertOk()->assertDontSee('test-secret-123');
        $this->assertContains('ssid auth wpa wifi_0/1 key test-secret-123',(array)$calls);$this->assertDatabaseHas('audit_logs',['action'=>'onu.detail.wifi.applied']);
    }
}
