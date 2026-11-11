<?php
namespace Tests\Unit;
use App\Services\Olt\{OltTrafficService,ZteSnmpDriver,ZteProvisioningService};
use Tests\TestCase;
class TrafficRateTest extends TestCase {
    public function test_counter64_delta_is_exact_above_float_integer_precision():void{
        $service=new OltTrafficService(new ZteSnmpDriver);
        $a=['entity'=>'ONU:1','at'=>100.0,'uptime'=>100,'discontinuity'=>'0','in'=>'18000000000000000000','out'=>'18000000000000000000'];
        $b=['entity'=>'ONU:1','at'=>110.0,'uptime'=>110,'discontinuity'=>'0','in'=>'18000000000000001000','out'=>'18000000000000002000'];
        $this->assertSame(['download_bps'=>1600.0,'upload_bps'=>800.0],$service->rate($a,$b));
        $this->assertNull($service->rate(null,$b));$this->assertNull($service->rate($a,array_replace($b,['uptime'=>1])));$this->assertNull($service->rate($a,array_replace($b,['in'=>'1'])));$this->assertNull($service->rate($a,array_replace($b,['entity'=>'ONU:2'])));$this->assertNull($service->rate($a,array_replace($b,['discontinuity'=>'3'])));
    }
    public function test_real_unconfigured_output_uses_serial_and_physical_position():void{
        $parser=app(ZteProvisioningService::class);$rows=$parser->unconfigured("OnuIndex Sn State\n-------\ngpon-onu_1/1/3:1 ZTEGC9945478 unknown\ngpon-onu_1/2/2:1 ZTEGC80615EA unknown\ngpon-onu_1/2/10:1 ZTEGCC4E11C3 unknown");
        $this->assertCount(3,$rows);$this->assertSame(['frame'=>1,'slot'=>2,'port'=>10,'detected_index'=>1,'sn'=>'ZTEGCC4E11C3','state'=>'unknown'],$rows[2]);$this->assertSame([],$parser->unconfigured('No related information'));
    }
}
