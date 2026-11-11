<?php
// Isolated loopback peer for configuration writes; never reaches real devices.
$server=stream_socket_server('tcp://127.0.0.1:0');$registered=[1=>['model'=>'F609','sn'=>'ZTEGC0D153C2']];$reject=($argv[1]??'')==='reject';
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();
while($client=@stream_socket_accept($server,60)){
    stream_set_timeout($client,5);fwrite($client,'Username:');$user=trim((string)fgets($client));fwrite($client,'Password:');$password=trim((string)fgets($client));
    if($user!=='admin'||$password!=='test-secret'){fclose($client);continue;}fwrite($client,"\r\nZXAN#");
    $prompt='ZXAN#';
    while(($line=fgets($client))!==false){
        $command=trim($line);$safe=preg_replace('/password\s+\S+/','password [REDACTED]',$command);echo json_encode(['command'=>$safe])."\n";flush();$output='';
        if($command==='show running-config')$output="hostname ZXAN\r\nusername admin password test-secret\r\nsnmp-server community test-community\r\ninterface gpon-olt_1/1/3\r\n!\r\nend";
        elseif($command==='show gpon onu uncfg')$output="OnuIndex Sn State\r\ngpon-onu_1/1/3:1 ZTEGC9945478 unknown\r\ngpon-onu_1/2/2:1 ZTEGC80615EA unknown\r\ngpon-onu_1/2/10:1 ZTEGCC4E11C3 unknown";
        elseif(preg_match('/^show running-config interface (gpon-olt_1\/1\/3)$/',$command,$m)){$output='interface '.$m[1];foreach($registered as $i=>$row)$output.="\r\n onu $i type {$row['model']} sn {$row['sn']}";$output.="\r\n!\r\nend";}
        elseif(preg_match('/^interface gpon-onu_1\/1\/3:(\d+)$/',$command,$m))$currentOnu=(int)$m[1];
        elseif(isset($currentOnu)&&str_starts_with($command,'name '))$registered[$currentOnu]['name']=substr($command,5);
        elseif(isset($currentOnu)&&in_array($command,['shutdown','no shutdown'],true))$registered[$currentOnu]['admin']=$command==='shutdown'?'disable':'enable';
        elseif(preg_match('/^no onu (\d+)$/',$command,$m))unset($registered[(int)$m[1]]);
        elseif(preg_match('/^show running-config interface gpon-onu_1\/1\/3:(\d+)$/',$command,$m)){$row=$registered[(int)$m[1]]??[];$output='interface gpon-onu_1/1/3:'.$m[1]."\r\n name ".($row['name']??'EXISTING')."\r\n!\r\nend";}
        elseif($command==='show gpon onu state gpon-olt_1/1/3'){$output="OnuIndex Admin State OMCC State Phase State\r\n";foreach($registered as $i=>$row){$admin=$row['admin']??'enable';$phase=$admin==='enable'?'working':'OffLine';$output.="1/1/3:$i $admin enable $phase 1(GPON)\r\n";}$output.='Total ONU: '.count($registered);}
        elseif(preg_match('/^onu (\d+) type (\S+) sn (\S+)$/',$command,$m))$registered[(int)$m[1]]=['model'=>$m[2],'sn'=>$m[3]];
        elseif($reject && str_starts_with($command,'wan-ip ')){$output='%Error: unsupported WAN command '.$command;}
        if($command==='configure terminal')$prompt='ZXAN(config)#';
        elseif(preg_match('/^pon-onu-mng gpon-onu_(.+)$/',$command,$pm))$prompt='ZXAN(gpon-onu-mng '.$pm[1].')#';
        elseif($command==='end')$prompt='ZXAN#';
        fwrite($client,$command."\r\n".$output."\r\n".$prompt);
    }fclose($client);
}
