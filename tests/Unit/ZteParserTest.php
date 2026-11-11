<?php

namespace Tests\Unit;

use App\Services\Olt\{OltConnectionException, ZteOutputParser, ZteTelnetClient};
use Tests\TestCase;

class ZteParserTest extends TestCase
{
    public function test_physical_detail_info_reads_alias_without_taking_serial_from_description(): void
    {
        $output="ONU interface: gpon-onu_1/1/10:31\n Name: SIDSMP-PAS1-BDG\n Type: F609\n Phase state: OffLine\n Serial number: ZTEGCCA26B50\n Password:\n Description: zone_Zone_1_descr__extid_ZTEGC89C8FEC\n Online Duration: 0h 0m 0s";
        $labels=(new ZteOutputParser)->onuDetailLabels($output,'1/1/10:31','ZTEGCCA26B50');
        $this->assertSame('SIDSMP-PAS1-BDG',$labels['device_name']);
        $this->assertSame('zone_Zone_1_descr__extid_ZTEGC89C8FEC',$labels['device_description']);
        $this->expectException(OltConnectionException::class);
        (new ZteOutputParser)->onuDetailLabels($output,'1/1/10:31','ZTEGC89C8FEC');
    }
    public function test_running_config_names_and_descriptions_are_scoped_to_each_onu_interface(): void
    {
        $labels=(new ZteOutputParser)->onuLabels("hostname ZXAN\ninterface gpon-olt_1/1/1\n description Nama Port\n!\ninterface gpon-onu_1/1/1:7\n name \"Budi Rumah\"\n description Jl Mawar 10\n gemport 1 name Internet tcont 1\n!\ninterface gpon-onu_1/2/5:12\n description Siti Toko\n!\ninterface gei_1/3/1\n name Uplink\n!");
        $this->assertSame(['device_name'=>'Budi Rumah','device_description'=>'Jl Mawar 10'],$labels['1/1/1:7']);
        $this->assertSame(['device_description'=>'Siti Toko'],$labels['1/2/5:12']);
        $this->assertCount(2,$labels);
    }
    public function test_baseinfo_uses_sn_column_instead_of_model_or_customer_name_that_looks_like_a_serial(): void
    {
        $rows=(new ZteOutputParser)->baseInfo("OnuIndex Type State SN Name\n1/1/1:1 ZXHNF660V10 enable ZTEGC2222222 CUSTOMER000001\n1/1/1:2 ZXHNF660V10 enable ZTEGC3333333 CUSTOMER000002\nTotal ONU: 2",'gpon-olt_1/1/1');
        $this->assertSame('ZTEGC2222222',$rows['1/1/1:1']['sn']);
        $this->assertSame('ZTEGC3333333',$rows['1/1/1:2']['sn']);
        $this->assertSame('ZXHNF660V10',$rows['1/1/1:1']['onu_type']);
        $this->assertSame('CUSTOMER000001',$rows['1/1/1:1']['name']);
    }

    public function test_baseinfo_serial_column_can_precede_model_with_multiword_header(): void
    {
        $rows=(new ZteOutputParser)->baseInfo("ONU Index Serial Number ONU Type State Name\ngpon-onu_1/1/1:1 5A544547C2222222 ZXHNF660V10 enable Subscriber One\nTotal: 1",'gpon-olt_1/1/1');
        $this->assertSame('ZTEGC2222222',$rows['1/1/1:1']['sn']);
        $this->assertSame('ZXHNF660V10',$rows['1/1/1:1']['onu_type']);
        $this->assertSame('Subscriber One',$rows['1/1/1:1']['name']);
    }

    public function test_baseinfo_without_serial_column_rejects_ambiguous_candidates_instead_of_inventing_duplicate_serials(): void
    {
        $this->expectException(OltConnectionException::class);
        (new ZteOutputParser)->baseInfo("OnuIndex Model Identifier\n1/1/1:1 ZXHNF660V10 ZTEGC2222222\nTotal: 1",'gpon-olt_1/1/1');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phaseStates')]
    public function test_physical_index_and_phase_state_normalization(string $phase, string $expected): void
    {
        $parser=new ZteOutputParser;
        foreach (['1/1/1:7','gpon-onu_1/1/1:7'] as $index) {
            $rows=$parser->states("$index enable disable $phase 1(GPON)\nONU Number: 0/1",'gpon-olt_1/1/1');
            $this->assertSame($expected,$rows['1/1/1:7']['phase_state']);
            $this->assertSame('enable',$rows['1/1/1:7']['admin_state']);
            $this->assertSame('disable',$rows['1/1/1:7']['omcc_state']);
        }
    }

    public static function phaseStates(): array
    {
        return [['working','working'],['WORKING','working'],['online','working'],['LOS','los'],
            ['DyingGasp','dying-gasp'],['dying-gasp','dying-gasp'],['dying_gasp','dying-gasp'],['OffLine','offline'],['offline/online','offline'],
            ['SyncMib','sync-mib'],['SYNC-MIB','sync-mib'],['sync_mib','sync-mib'],['Logging','logging'],['AUTH','auth']];
    }

    public function test_global_inventory_keeps_386_onus_across_32_ports_with_transition_states(): void
    {
        $lines=['OnuIndex Admin State OMCC State Phase State'];
        $phases=['working','SyncMib','Logging','Auth','LOS','DyingGasp'];
        for($p=1;$p<=32;$p++){
            $slot=$p<=16?1:2;$port=($p-1)%16+1;
            for($onu=1;$onu<=($p<=30?12:13);$onu++)$lines[]='gpon-onu_1/'.$slot.'/'.$port.':'.$onu.' enable enable '.$phases[($onu-1)%6];
        }
        $lines[]='Total: 386';$ports=(new ZteOutputParser)->globalStates(implode("\n",$lines));
        $this->assertCount(32,$ports);$this->assertSame(386,array_sum(array_map('count',$ports)));
        $this->assertSame('sync-mib',$ports['gpon-olt_1/2/16']['1/2/16:2']['phase_state']);
        $this->assertSame('logging',$ports['gpon-olt_1/2/16']['1/2/16:3']['phase_state']);
        $this->assertSame('auth',$ports['gpon-olt_1/2/16']['1/2/16:4']['phase_state']);
    }

    public function test_online_total_recap_counts_registered_onus_and_accepts_zero_of_zero(): void
    {
        $parser=new ZteOutputParser;
        foreach (['ONU Number: 0/2','Total ONU: 2','Total ONU: 0/2','Total ONU Number: 2','Total number of ONUs: 2'] as $recap) {
            $rows=$parser->states("1/1/1:1 disable disable OffLine 1(GPON)\ngpon-onu_1/1/1:7 enable disable DyingGasp 1(GPON)\n$recap",'gpon-olt_1/1/1');
            $this->assertCount(2,$rows);
        }
        $this->assertSame([],$parser->states('ONU Number: 0/0','gpon-olt_1/1/1'));
        $this->assertSame([],$parser->states('Total ONU: 0','gpon-olt_1/1/1'));
        $this->assertSame([],$parser->states('', 'gpon-olt_1/1/1'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPhysicalStates')]
    public function test_incomplete_duplicate_foreign_or_unknown_state_is_never_saved(string $output): void
    {
        $this->expectException(OltConnectionException::class);
        (new ZteOutputParser)->states($output,'gpon-olt_1/1/1');
    }

    public static function invalidPhysicalStates(): array
    {
        return [
            ["1/1/1:1 disable disable OffLine 1(GPON)\nONU Number: 0/13"],
            ["OnuIndex Admin State OMCC State Phase State\nONU Number: 0/13"],
            ["1/1/1:1 enable enable working 1(GPON)\nONU Number: 2/1"],
            ["1/1/1:1 enable enable unsupported 1(GPON)\nTotal ONU: 1"],
            ["1/1/2:1 enable enable working 1(GPON)\nTotal ONU: 1"],
            ["1/1/1:1 enable enable working 1(GPON)\n1/1/1:1 enable enable working 1(GPON)\nTotal ONU: 2"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gponCards')]
    public function test_gpon_board_prefixes_and_online_states(string $type, string $status): void
    {
        $boards=(new ZteOutputParser)->cards("Rack Shelf Slot CfgType RealType Port HardVer SoftVer Status\n1 1 2 $type $type 8 H1 V2.1.0 $status");
        $this->assertSame($status,$boards[0]['status']);
        $this->assertTrue($boards[0]['gpon']);
        $this->assertSame(8,$boards[0]['port_count']);
    }

    public static function gponCards(): array
    {
        return array_map(fn ($type,$index)=>[$type,['INSERVICE','ONLINE','IS'][$index%3]],
            ['GTGO','GTGH','GTGHK','GTGOG','GTGHG','GFGH','GFGL','GFGO','GTGX','GFGX'], range(0,9));
    }

    public function test_offline_gpon_and_control_cards_are_not_polled_and_missing_capacity_is_not_guessed(): void
    {
        $parser=new ZteOutputParser;
        $boards=$parser->cards("Shelf Slot CfgType RealType Port HardVer SoftVer Status\n1 1 GFGH GFGH 16 H1 V2 OFFLINE\n1 3 SMXA SMXA 0 H1 V2 INSERVICE");
        $this->assertFalse($boards[0]['gpon']); $this->assertFalse($boards[1]['gpon']);
        $this->expectException(OltConnectionException::class);
        $parser->cards("Shelf Slot CfgType RealType Port HardVer SoftVer Status\n1 1 GFGX GFGX N/A H1 V2 INSERVICE");
    }

    public function test_system_group_description_firmware_and_uptime_and_running_version_precedence(): void
    {
        $parser=new ZteOutputParser;
        $group="System Description: ZTE C320 Firmware Version: V2.2.0P1\nSystemUpTime: 14 days";
        $this->assertSame('V2.2.0P1',$parser->system('',group:$group)['firmware_version']);
        $this->assertSame('14 days',$parser->system('',group:$group)['uptime']);
        $this->assertSame('V2.1.0',$parser->system("BootROM Version: V4.0.7\nSoftware Version: V2.1.0",group:$group)['firmware_version']);
    }

    public function test_only_recognized_empty_onu_errors_are_ignored_for_onu_read_commands(): void
    {
        $parser=new ZteOutputParser;
        foreach (['%Code 32318: No related information to show.','No related information','%Error: Noonu'] as $output) {
            ZteTelnetClient::assertCommandAccepted($output,'show gpon onu state gpon-olt_1/1/1');
            $this->assertSame([],$parser->states($output,'gpon-olt_1/1/1'));
            $this->assertSame([],$parser->baseInfo($output,'gpon-olt_1/1/1'));
            $this->assertSame([],$parser->power($output,'gpon-olt_1/1/1'));
        }
        $this->expectException(OltConnectionException::class);
        ZteTelnetClient::assertCommandAccepted('%Error: Noonu','reboot');
    }

    public function test_mixed_error_and_empty_response_is_not_silently_accepted(): void
    {
        $this->expectException(OltConnectionException::class);
        ZteTelnetClient::assertCommandAccepted("No related information\n%Error: Permission denied",'show gpon onu state gpon-olt_1/1/1');
    }

    public function test_firmware_prefers_software_not_bootrom_and_table_metrics_use_highest_board(): void
    {
        $data=(new ZteOutputParser)->system(
            "BootROM Version: V4.0.7\nSoftware Version\n: V2.1.0P3\nBoard Up Time: 3 days",
            "Rack Shelf Slot CPU(5s) CPU(1m) CPU(5m) PhyMem(MB) Memory\n1 1 1 12% 9% 8% 512 44%\n1 1 3 7% 6% 5% 1024 28%",
            "Card Temperature:\nSlot Temperature(Celsius scale)\n1 41\n2 N/A.\n3 49",
            'Started before: 7 days, 3 hours'
        );
        $this->assertSame('V2.1.0P3',$data['firmware_version']);
        $this->assertSame(12.0,$data['cpu']);
        $this->assertSame(44.0,$data['memory_usage']);
        $this->assertSame(49.0,$data['temperature']);
        $this->assertSame('7 days, 3 hours',$data['uptime']);
    }
}
