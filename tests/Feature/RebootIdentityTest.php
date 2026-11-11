<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\Olt\{CliClient,ZteTelnetDriver,ZteOutputParser,OltConnectionException};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class RebootIdentityTest extends TestCase {
    use RefreshDatabase;
    protected function setUp():void {parent::setUp();$this->seed(\Database\Seeders\DatabaseSeeder::class);}
    #[\PHPUnit\Framework\Attributes\DataProvider('identities')]
    public function test_reboot_requires_current_identity(string $identity,bool $allowed):void {
        $onu=User::where('role','isp_owner')->first()->tenant->onus()->with(['olt','port'])->first();$commands=[];
        $index=str_replace('gpon-olt_','',$onu->port->interface_name).':'.$onu->onu_index;
        $sn=$identity==='match'?$onu->sn:'ZTEGFFFFFFFF';
        $client=\Mockery::mock(CliClient::class);$client->shouldReceive('connect','close')->once()->andReturnNull();
        $client->shouldReceive('command')->andReturnUsing(function($command)use(&$commands,$identity,$index,$sn){
            $commands[]=$command;
            if(str_starts_with($command,'show '))return $identity==='missing'?"OnuIndex Type SN State\nTotal: 0":"OnuIndex Type SN State\n$index F609 $sn ready\nTotal: 1";
            return 'Reboot accepted';
        });
        $driver=\Mockery::mock(ZteTelnetDriver::class,[app(ZteOutputParser::class)])->makePartial();$driver->shouldReceive('client')->andReturn($client);
        try {$driver->reboot($onu);$this->assertTrue($allowed);}catch(OltConnectionException $e){$this->assertFalse($allowed);$this->assertSame('identity_changed',$e->reason);}
        $this->assertSame($allowed,in_array('reboot',$commands,true));
        if(!$allowed)$this->assertFalse(in_array('configure terminal',$commands,true));
    }
    public static function identities():array {return [['match',true],['changed',false],['missing',false]];}

    #[\PHPUnit\Framework\Attributes\DataProvider('readFailures')]
    public function test_read_fallback_is_limited_and_timeout_never_writes(string $reason,bool $allowed):void {
        $onu=User::where('role','isp_owner')->first()->tenant->onus()->with(['olt','port'])->first();$commands=[];
        $client=\Mockery::mock(CliClient::class);$client->shouldReceive('connect','close')->once()->andReturnNull();
        $client->shouldReceive('command')->andReturnUsing(function($command)use(&$commands,$reason,$onu){
            $commands[]=$command;
            if(str_starts_with($command,'show gpon onu baseinfo'))throw new OltConnectionException('Read failed',$reason);
            if(str_starts_with($command,'show running-config'))return 'interface '.$onu->port->interface_name."\n onu ".$onu->onu_index.' type F609 sn '.$onu->sn."\n!";
            return 'Reboot accepted';
        });
        $driver=\Mockery::mock(ZteTelnetDriver::class,[app(ZteOutputParser::class)])->makePartial();$driver->shouldReceive('client')->andReturn($client);
        try{$driver->reboot($onu);$this->assertTrue($allowed);}catch(OltConnectionException $e){$this->assertFalse($allowed);$this->assertSame($reason,$e->reason);}
        $this->assertSame($allowed,in_array('reboot',$commands,true));
        if(!$allowed)$this->assertCount(1,$commands);
    }
    public static function readFailures():array {return [['command_rejected',true],['parse_failed',true],['timeout',false]];}
}
