<?php
// Loopback-only protocol fixture. Never contacts a user's OLT or logs credentials.
$scenario = $argv[1] ?? 'normal';
$labelPolls = 0;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) exit(1);
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n"; flush();

function line($client): ?string {
    $text='';
    while(!feof($client)) {
        $byte=fread($client,1);
        if($byte==='') return null;
        if(ord($byte)===255) { $verb=fread($client,1); if($verb!=='' && in_array(ord($verb),[251,252,253,254],true))fread($client,1); continue; }
        if($byte==="\n") return rtrim($text,"\r");
        $text.=$byte;
    }
    return null;
}

while($client=@stream_socket_accept($server,120)) {
    stream_set_timeout($client,5);
    // Split option negotiation and a login prompt between packets.
    fwrite($client,chr(255)); usleep(1000); fwrite($client,chr(251).chr(1)."Welcome ZTE\r\nUser"); usleep(1000); fwrite($client,'name:');
    if(line($client)===null) { fclose($client); continue; }
    fwrite($client,'Password:'); if(line($client)===null) { fclose($client); continue; }
    if($scenario==='auth') { fwrite($client,"Login incorrect\r\nUsername:"); fclose($client); continue; }
    $userMode=str_starts_with($scenario,'user-');
    fwrite($client,"\r\nZXAN".($userMode?'>':'#')); $configured=false; $awaitYes=false;
    while(($command=line($client))!==null) {
        echo json_encode(['command'=>$command])."\n"; flush();
        if($scenario==='timeout' && $command==='show version-running') { usleep(800000); break; }
        if($scenario==='setup-timeout' && $command==='terminal length 0') { usleep(800000); break; }
        if($scenario==='labels-timeout' && $command==='show running-config') { usleep(800000); break; }
        if($scenario==='optical-timeout' && str_starts_with($command,'show pon power onu-rx ')) { usleep(800000); break; }
        if($scenario==='inventory-timeout' && $command==='show gpon onu state gpon-olt_1/1/2') { usleep(800000); break; }
        if($command==='show streaming') {
            fwrite($client,$command."\r\n");
            foreach (['ZTE ', 'stream ', 'AB'.chr(8).'C ', 'is ', 'alive'] as $part) { usleep(80000); fwrite($client,$part); }
            fwrite($client,"\r\n\x1b[32mZXAN#\x1b[0m\x00\r\n"); continue;
        }
        if($command==='enable') {
            if($scenario==='user-password' || $scenario==='user-denied') {
                fwrite($client,"enable\r\nPassword:");
                $password=line($client);
                if($password!=='test-secret') { fclose($client); continue 2; }
                if($scenario==='user-password')$userMode=false;
            } elseif($scenario==='user-direct')$userMode=false;
            fwrite($client,($userMode?"%Error: Access denied\r\n":"\r\n").'ZXAN'.($userMode?'>':'#'));
            continue;
        }
        $slot=1; $port=1; if(preg_match('/gpon-olt_1\/(\d+)\/(\d+)/',$command,$m)) { $slot=(int)$m[1]; $port=(int)$m[2]; }
        $rows=$slot===1 && $port===1;
        $wide=in_array($scenario,['gtgh32','optical-timeout','duplicate-ports'],true);
        $physical=str_starts_with($scenario,'physical-');
        $physicalStates=[]; $physicalBase=[];
        for($index=1;$index<=13;$index++) {
            $phase=$index===7?'DyingGasp':($index===13?'LOS':'OffLine');
            $physicalStates[]=($index%2===0?'gpon-onu_':'')."1/1/1:$index    disable    disable    $phase    1(GPON)";
            $physicalBase[]=' onu '.$index.' type ZTE-F660 sn ZTEG'.sprintf('%08d', $index);
        }
        $empty = match($scenario) { 'empty-code' => '%Code 32318: No related information to show.', 'empty-noonu' => '%Error: Noonu', default => 'No related information' };
        if ($scenario==='device-labels' && $command==='show running-config') {
            $labelPolls++;
            $output="interface gpon-olt_1/1/1\r\n description Nama Port Bukan Pelanggan\r\n!\r\ninterface gpon-onu_1/1/1:1\r\n name \"".($labelPolls===1?'Budi Rumah':'Budi Baru')."\"\r\n description ".($labelPolls===1?'Jl Mawar 10':'Jl Melati 20')."\r\n gemport 1 name BUKAN_NAMA_PELANGGAN tcont 1\r\n!\r\ninterface gpon-onu_1/1/1:2\r\n name Siti Toko\r\n description Kios Blok B\r\n!\r\ninterface gei_1/3/1\r\n description Uplink Bukan Pelanggan\r\n!";
            fwrite($client,$command."\r\n".$output."\r\nZXAN#"); continue;
        }
        $output=match(true) {
            $scenario==='real-interface-labels' && $command==='show card' => "Shelf Slot CfgType RealType Port HardVer SoftVer Status\r\n1 1 GTGH GTGH 16 H1 V2.1.0 INSERVICE",
            $scenario==='real-interface-labels' && $command==='show running-config' => "hostname ZXAN\r\ninterface gei_1/3/1\r\n description Uplink\r\n!\r\nend",
            $scenario==='real-interface-labels' && str_starts_with($command,'show gpon onu state ') => $port===10?"OnuIndex Admin State OMCC State Phase State\r\n1/1/10:31 disable disable OffLine 1(GPON)\r\n1/1/10:33 enable disable LOS 1(GPON)\r\nONU Number: 0/2":"ONU Number: 0/0",
            $scenario==='real-interface-labels' && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type SN State\r\n1/1/10:31 F609 ZTEGCCA26B50 ready\r\n1/1/10:33 F609 ZTEGC8A6C5A3 ready\r\nTotal: 2",
            $scenario==='real-interface-labels' && $command==='show running-config interface gpon-onu_1/1/10:31' => "Building configuration...\r\ninterface gpon-onu_1/1/10:31\r\n name SIDSMP-PAS1-BDG\r\n description \$\$\$\$zone_Zone_1_descr__extid_ZTEGC89C8FEC\r\n tcont 1 profile UP-1M\r\n gemport 1 name internet tcont 1\r\n shutdown\r\n service-port 1 vport 1 user-vlan 123 vlan 123\r\n!\r\nend",
            $scenario==='real-interface-labels' && $command==='show gpon onu detail-info gpon-onu_1/1/10:33' => "ONU interface: gpon-onu_1/1/10:33\r\n Name: CIFO-BANJIR-ADIPURA\r\n Type: F609\r\n State: ready\r\n Phase state: LOS\r\n SN Bind: enable with SN check\r\n Serial number: ZTEGC8A6C5A3\r\n Password:\r\n Description: zone_Zone_1_descr__extid_ZTEGC89C8FEC\r\n Online Duration: 0h 0m 0s\r\n 1 0000-00-00 00:00:00 0000-00-00 00:00:00",
            $command==='' => '',
            $command==='terminal length 0' && $scenario==='paging' => '%Error: Invalid input detected',
            $command==='terminal length 0' && str_starts_with($scenario,'fallback-') => 'Command not found',
            $command==='show version-running' && (str_starts_with($scenario,'fallback-') || $scenario==='system-only') => '%Error: Unsupported command',
            $command==='show system-group' && in_array($scenario,['fallback-card','fallback-legacy'],true) => '%Error: Unsupported command',
            $command==='show version' && $scenario==='fallback-legacy' => 'Software Version: V1.2.5',
            $command==='show version' && str_starts_with($scenario,'fallback-') => '%Error: Unknown command',
            $command==='show version' && $scenario==='firmware-modern' => '%Error 20204: Ambiguous command: "show version"',
            $command==='show version' && $scenario==='system-only' => '%Error 20204: Ambiguous command: "show version"',
            $command==='show card' && $scenario==='system-only' => '%Error: Unsupported command',
            $command==='show system-group' && $scenario==='system-only' => "System Description: ZTE C320 Software Version V2.2.0P1\r\nSystem Up Time: 41 days",
            $command==='show card' && $scenario==='fallback-legacy' => '%Error: Unsupported command',
            $command==='show card' && in_array($scenario,['fallback-group','fallback-all'],true) => 'Invalid input',
            $command==='show system-group' && $scenario==='fallback-all' => 'Command not found',
            $command==='show system-group' => 'System uptime is 23 days',
            in_array($command,['show rejected','show rejected test-secret'],true) => '%Error: Invalid input test-secret community test-community',
            $command==='show card' && $scenario==='sync-rejected' => '%Error: Permission denied test-secret',
            $command==='terminal length 0' => '',
            $command==='show card' && $wide => "Shelf Slot CfgType RealType Port HardVer SoftVer Status\r\n1 1 GTGH GTGH 16 H1 V2.1.0 INSERVICE\r\n1 2 GTGH GTGH 16 H1 V2.1.0 INSERVICE\r\n1 3 SMXA SMXA 0 H1 V2.1.0 INSERVICE\r\n1 4 SMXA SMXA 0 H1 V2.1.0 STANDBY",
            $command==='show gpon onu state' && $wide => "OnuIndex Admin State OMCC State Phase State\r\n1/1/1:1 enable enable working\r\n1/1/1:2 enable disable dying-gasp\r\n1/2/5:1 enable disable los\r\nTotal: 3",
            $wide && $scenario!=='duplicate-ports' && $slot===2 && $port===5 && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type State SN Name\r\n1/2/5:1 ZTE-F660 enable ZTEGC4444444 Subscriber_Three\r\nTotal: 1",
            $command==='show temperature' && $scenario==='temp-none' => '%Error: Unsupported command',
            $physical && $command==='show gpon onu state gpon-olt_1/1/1' => implode("\r\n",$scenario==='physical-truncated'?array_slice($physicalStates,0,12):$physicalStates)."\r\nONU Number: 0/13",
            $physical && $command==='show gpon onu state gpon-olt_1/1/2' => 'ONU Number: 0/0',
            $physical && $command==='show gpon onu state gpon-olt_1/1/3' => '',
            $scenario==='physical-late-truncated' && $command==='show gpon onu state gpon-olt_1/1/8' => "OnuIndex Admin State OMCC State Phase State\r\n1/1/8:1 disable disable OffLine 1(GPON)\r\nONU Number: 0/13",
            $physical && $command==='show gpon onu baseinfo gpon-olt_1/1/1' => '%Error: Unsupported command',
            $physical && $command==='show running-config interface gpon-olt_1/1/1' => "interface gpon-olt_1/1/1\r\n".implode("\r\n",$physicalBase)."\r\n!",
            $rows && $scenario==='duplicate-sn' && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type State SN Name\r\n1/1/1:1 ZTE-F660 enable ZTEGC2222222 One\r\n1/1/1:2 ZTE-F660 enable ZTEGC2222222 Two\r\nTotal: 2",
            $rows && $scenario==='model-serial' && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type State SN Name\r\n1/1/1:1 ZXHNF660V10 enable ZTEGC2222222 One\r\n1/1/1:2 ZXHNF660V10 enable ZTEGC3333333 Two\r\nTotal: 2",
            $wide && $slot===2 && $port===5 && str_starts_with($command,'show gpon onu state ') => "OnuIndex Admin State OMCC State Phase State\r\n1/2/5:1 enable disable los\r\nTotal ONU: 1",
            $scenario==='duplicate-ports' && $slot===2 && $port===5 && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type State SN Name\r\n1/2/5:1 ZTE-F660 enable ZTEGC2222222 Duplicate_Port\r\nTotal: 1",
            in_array($command,['show version-running','show version'],true) => 'ZTE ZXA10 '.($scenario==='c300'?'C300':'C320')."\r\nSoftware Version: V2.1.0\r\nSystem uptime is 23 days, 02 hours",
            $command==='show card' => $scenario==='malformed'?'Unknown board format':"Rack Shelf Slot CfgType RealType Port HardVer SoftVer Status\r\n1 1 1 ".($scenario==='c300'?'GTGH GTGHG 16':'GTGO GTGOG 8')." 120301 V2.1.0 INSERVICE\r\n1 1 3 SMXA SMXA 0 110702 V2.1.0 INSERVICE",
            $command==='show processor' => 'CPU load: 12%'."\r\n".'Memory usage: 38%',
            $command==='show temperature' => 'Temperature: 42.5',
            $command==='show card-temperature' && $scenario!=='temp-none' && $scenario!=='temp-fallback' => "Rack Shelf Slot Temperature(C)\r\n1 1 1 42.5\r\n1 1 3 40.0",
            $scenario==='device-description' && $command==='show running-config' => "interface gpon-onu_1/1/1:1\r\n description Pelanggan Deskripsi\r\n!",
            in_array($scenario,['device-labels','device-description'],true) && $rows && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type SN State\r\n1/1/1:1 ZTE-F660 ZTEGC2222222 ready\r\n1/1/1:2 ZTE-F660 ZTEGC3333333 ready\r\nTotal: 2",
            $command==='show temp' && $scenario==='temp-fallback' => "Slot Temp(C)\r\n1 47\r\n2 43",
            str_starts_with($scenario,'empty-') && preg_match('/^show (?:gpon onu (?:state|baseinfo)|pon power (?:onu-rx|olt-rx)) /',$command) => $empty,
            $scenario==='optical-empty' && str_starts_with($command,'show pon power ') => '%Error: Noonu',
            $scenario==='config-ambiguous' && $rows && str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Model Identifier\r\n1/1/1:1 ZXHNF660V10 ZTEGC2222222\r\n1/1/1:2 ZXHNF660V10 ZTEGC3333333\r\nTotal: 2",
            str_starts_with($scenario,'config-') && str_starts_with($command,'show gpon onu baseinfo ') => '%Error 20204: Ambiguous command',
            str_starts_with($scenario,'config-') && str_starts_with($command,'show running-config interface ') => 'interface gpon-olt_1/1/'.$port."\r\n".($rows ? ($scenario==='config-malformed'?" onu 1 type ZTE-F660 sn INVALID\r\n":" onu 1 type ZTE-F660 sn ZTEGC2222222\r\n onu 2 type ZTE-F660 sn 5A544547C3333333\r\n") : '').'!',
            str_starts_with($command,'show gpon onu state ') => "OnuIndex Admin State OMCC State Phase State\r\n".($rows?"1/1/1:1 enable enable working\r\n1/1/1:2 enable disable dying-gasp\r\nTotal: 2":"Total: 0"),
            str_starts_with($command,'show gpon onu baseinfo ') => "OnuIndex Type State SN Name\r\n".($rows?"1/1/1:1 ZTE-F660 enable ZTEGC2222222 Subscriber_One\r\n1/1/1:2 ZTE-F660 enable ZTEGC3333333 Subscriber_Two\r\nTotal: 2":"Total: 0"),
            str_starts_with($command,'show pon power onu-rx ') => "OnuIndex Rx power\r\n".($rows?"1/1/1:1 -27.00(dbm)\r\n1/1/1:2 N/A":"No related information"),
            str_starts_with($command,'show pon power olt-rx ') => "OnuIndex Rx power\r\n".($rows?"1/1/1:1 -28.30(dbm)\r\n1/1/1:2 N/A":"No related information"),
            $command==='configure terminal' => '',
            str_starts_with($command,'pon-onu-mng gpon-onu_') => '',
            $command==='reboot' => 'Are you sure to reboot? [yes/no]:',
            $command==='yes' => $scenario==='uncertain'?'': 'Reboot successfully',
            $command==='exit' => '',
            $command==='show gpon onu uncfg' => "OnuIndex Sn State\r\nNo related information",
            $command==='show running-config interface gpon-olt_1/1/1' => 'interface gpon-olt_1/1/1',
            default => '%Error: Unknown command',
        };
        if($command==='configure terminal')$configured=true;
        if($command==='reboot') { fwrite($client,$command."\r\n".$output); continue; }
        $prompt=$configured?'ZXAN(config-pon-onu-mng)#':'ZXAN'.($userMode?'>':'#');
        if($scenario==='banner' && $command==='') {
            fwrite($client,"\r\n".$prompt); usleep(20000);
            fwrite($client,chr(255).chr(251)); usleep(1000); fwrite($client,chr(3)."\r\nDelayed ZTE banner\r\n".$prompt);
            continue;
        }
        if($scenario==='paging' && in_array($command,['show version-running','show version'],true)) {
            fwrite($client,$command."\r\nZTE ZXA10 C320\r\n--More--");
            if(fread($client,1)!==' ') { fclose($client); continue 2; }
            fwrite($client,"Software Version: V2.1.0\r\n".$prompt);
            continue;
        }
        fwrite($client,$command."\r\n".$output."\r\n".$prompt);
        if($scenario==='drain-tail'&&$command==='show gpon onu state gpon-olt_1/1/1') {
            usleep(20000);
            fwrite($client,chr(255).chr(251));usleep(1000);
            fwrite($client,chr(3)."\r\nSyncMib\r\n%Error: Unknown command SyncMib\r\n".$prompt);
        }
    }
    fclose($client);
}
