<?php

namespace Tests\Feature;

use App\Models\{Olt, OnuOpticalLog, User};
use App\Services\Olt\{RealOltOperations, YotcHttpDriver};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YotcIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
    }

    private function owner(): User { return User::where('email', 'isp_owner@bima.test')->firstOrFail(); }

    private function input(): array
    {
        return ['name'=>'YOTC-MONITOR','chassis_type'=>'C1600','ip_address'=>'192.168.80.10',
            'telnet_port'=>80,'cli_transport'=>'http','connection_mode'=>'real','telnet_user'=>'monitor',
            'telnet_password'=>'fixture-web-secret','snmp_port'=>161,'connection_timeout'=>5];
    }

    private function olt(): Olt
    {
        return $this->owner()->tenant->olts()->create([
            'name'=>'YOTC-MONITOR','chassis_type'=>'C1600','ip_address'=>'192.168.80.10',
            'telnet_port'=>80,'cli_transport'=>'http','connection_mode'=>'real','telnet_user'=>'monitor',
            'telnet_pass_enc'=>'fixture-web-secret','snmp_enabled'=>false,'monitoring_enabled'=>false,
        ])->fresh();
    }

    private function snapshot(): array
    {
        $onus=[];
        for ($index=1; $index<=3; $index++) {
            $onus[]=['onu_index'=>$index,'sn'=>'AA:BB:CC:DD:EE:0'.$index,'name'=>'Subscriber '.$index,
                'device_name'=>'Subscriber '.$index,'phase_state'=>'working','rx_power'=>null,'olt_rx_power'=>null];
        }
        return ['system'=>['cpu'=>17,'temperature'=>80,'memory_usage'=>59.17,'uptime'=>'36 hari','firmware_version'=>'fixture-V1'],
            'boards'=>[['shelf'=>1,'slot'=>1,'type'=>'epu8a','status'=>'Normal','port_count'=>8,'gpon'=>false]],
            'ports'=>[['slot_no'=>1,'port_no'=>1,'interface_name'=>'epon-olt_1/1','onus'=>$onus]],
            'warnings'=>[],'complete_inventory'=>true];
    }

    public function test_web_registration_encrypts_credentials_and_uses_real_monitoring(): void
    {
        config(['nms.subscription'=>false]);
        $this->actingAs($this->owner())->postJson('/olts', $this->input()+['connection_mode'=>'simulator'])->assertCreated();
        $olt=Olt::where('name','YOTC-MONITOR')->firstOrFail();
        $this->assertSame('real',$olt->connection_mode);
        $this->assertFalse($olt->snmp_enabled);
        $this->assertSame('fixture-web-secret',$olt->telnet_pass_enc);
        $this->assertNotSame('fixture-web-secret',$olt->getRawOriginal('telnet_pass_enc'));
        $this->getJson('/dashboard/data?compact=1')->assertOk()->assertDontSee('fixture-web-secret');
        $this->postJson('/olts',array_replace($this->input(),['name'=>'YOTC-WRONG','cli_transport'=>'telnet']))
            ->assertUnprocessable()->assertJsonValidationErrors('cli_transport');
        $this->putJson('/olts/'.$olt->id,array_diff_key(array_replace($this->input(),['chassis_type'=>'C320','snmp_community'=>'fixture']),['cli_transport'=>true]))
            ->assertUnprocessable()->assertJsonValidationErrors('cli_transport');
    }

    public function test_web_connection_test_selects_yotc_and_keeps_secrets_out_of_response(): void
    {
        $this->mock(YotcHttpDriver::class)->shouldReceive('test')->once()->withArgs(function (Olt $probe) {
            return $probe->chassis_type==='C1600' && $probe->telnet_pass_enc==='fixture-web-secret'
                && !array_key_exists('telnet_password',$probe->getAttributes());
        })->andReturn(['authenticated'=>true,'version'=>'fixture-V1','warnings'=>[]]);
        $this->actingAs($this->owner())->postJson('/olts/test-connection',$this->input()+['protocol'=>'web'])
            ->assertOk()->assertJsonPath('results.web.authenticated',true)->assertDontSee('fixture-web-secret');
    }

    public function test_full_sync_only_updates_optics_for_sampled_onus_and_preserves_local_names(): void
    {
        $olt=$this->olt();$first=$this->snapshot();
        $first['ports'][0]['onus'][0]=array_replace($first['ports'][0]['onus'][0],['rx_power'=>-21.67,'olt_rx_power'=>-21.31,'optical_attempted'=>true]);
        $second=$this->snapshot();
        $second['ports'][0]['onus'][1]=array_replace($second['ports'][0]['onus'][1],['rx_power'=>-23.5,'olt_rx_power'=>-22.0,'optical_attempted'=>true]);
        $this->mock(YotcHttpDriver::class)->shouldReceive('snapshot')->twice()->andReturn($first,$second);
        $real=app(RealOltOperations::class);$real->sync($olt);
        $one=$olt->onus()->where('onu_index',1)->firstOrFail();$two=$olt->onus()->where('onu_index',2)->firstOrFail();
        $observed=$one->optical_observed_at;
        $this->assertSame(-21.67,$one->rx_power);
        $this->assertNull($two->optical_observed_at);
        $this->assertSame(1,OnuOpticalLog::whereIn('onu_id',$olt->onus()->pluck('id'))->count());
        $one->update(['name'=>'Local customer override']);
        $this->travel(10)->seconds();$real->sync($olt->fresh());
        $this->assertSame(-21.67,$one->fresh()->rx_power);
        $this->assertTrue($one->fresh()->optical_observed_at->equalTo($observed));
        $this->assertSame('Local customer override',$one->fresh()->name);
        $this->assertSame(-23.5,$two->fresh()->rx_power);
        $this->assertSame(3,$olt->onus()->current()->count());
        $this->assertSame(2,OnuOpticalLog::whereIn('onu_id',$olt->onus()->pluck('id'))->count());
    }

    public function test_partial_optical_poll_preserves_port_counts_and_unsampled_freshness(): void
    {
        $olt=$this->olt();$full=$this->snapshot();$partial=$this->snapshot();
        $partial['ports'][0]['optical_attempted']=true;
        $partial['ports'][0]['onus']=array_slice($partial['ports'][0]['onus'],0,1);
        $partial['ports'][0]['onus'][0]+= ['optical_attempted'=>true];
        $partial['ports'][0]['onus'][0]['rx_power']=-24.0;
        $partial['ports'][0]['onus'][0]['olt_rx_power']=-23.0;
        $this->mock(YotcHttpDriver::class)->shouldReceive('snapshot')->twice()->andReturn($full,$partial);
        $real=app(RealOltOperations::class);$real->poll($olt,'inventory');$real->poll($olt->fresh(),'optics');
        $port=$olt->ports()->firstOrFail();
        $this->assertSame(3,$port->total_onu);$this->assertSame(3,$port->online_onu);
        $this->assertSame(1,$olt->onus()->whereNotNull('optical_observed_at')->count());
        $this->assertSame(3,$olt->onus()->current()->count());
    }

    public function test_names_does_not_create_an_unobserved_replacement_identity(): void
    {
        $olt=$this->olt();$initial=$this->snapshot();$names=$this->snapshot();
        $names['ports'][0]['onus'][0]['sn']='11:22:33:44:55:66';
        $this->mock(YotcHttpDriver::class)->shouldReceive('snapshot')->twice()->andReturn($initial,$names);
        $real=app(RealOltOperations::class);$real->poll($olt,'inventory');$real->poll($olt->fresh(),'names');
        $this->assertSame(3,$olt->onus()->current()->count());
        $this->assertDatabaseMissing('onus',['olt_id'=>$olt->id,'sn'=>'11:22:33:44:55:66']);
    }
}
