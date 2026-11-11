<?php
// Stateful loopback fixture. Never connects to a user's OLT.
$server=stream_socket_server('tcp://127.0.0.1:0');$i=$argv[1];$sn=$argv[2];$added='';
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($server,false),':'),1)])."\n";flush();
while($client=@stream_socket_accept($server,30)){
    stream_set_timeout($client,5);fwrite($client,'Username:');$user=trim((string)fgets($client));fwrite($client,'Password:');$password=trim((string)fgets($client));
    if($user!=='admin'||$password!=='test-secret'){fclose($client);continue;}fwrite($client,"\r\nZXAN#");$prompt='ZXAN#';
    while(($line=fgets($client))!==false){$command=trim($line);$out='';
        if(str_starts_with($command,'show gpon onu detail-info'))$out='Serial number: '.$sn;
        elseif($command==='show running-config interface '.$i)$out=str_replace('gpon-onu_1/1/1:1',$i,file_get_contents(__DIR__.'/onu-tool-interface.txt'))."\n".$added;
        elseif($command==='show onu running config '.$i)$out=str_replace('gpon-onu_1/1/1:1',$i,file_get_contents(__DIR__.'/onu-tool-management.txt'));
        elseif($command==='show gpon profile tcont')$out="Profile name :UP-1M\nProfile name :UP-2M";
        elseif($command==='show gpon profile traffic')$out='Profile name :DOWN-1M';
        elseif($command==='show gpon onu profile vlan')$out='Profile name :STARZONE';
        elseif($command==='show gpon onu profile ip')$out='Profile name :DATA-UTAMA';
        elseif($command==='tcont 2 name tcont2 profile UP-2M')$added=$command;
        elseif($command==='configure terminal')$prompt='ZXAN(config)#';
        elseif($command==='interface '.$i)$prompt='ZXAN(config-if)#';
        elseif($command==='end')$prompt='ZXAN#';
        elseif(!in_array($command,['terminal length 0','terminal width 512','terminal width 0'],true))$out='%Error: command not expected by fixture';
        fwrite($client,$command."\r\n".$out."\r\n".$prompt);
    }fclose($client);
}
