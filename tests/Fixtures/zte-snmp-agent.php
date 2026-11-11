<?php
// Minimal SNMPv2c loopback agent for standard system OIDs; no real network devices.
$socket=stream_socket_server('udp://127.0.0.1:0',$errno,$error,STREAM_SERVER_BIND);
echo json_encode(['port'=>(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1)])."\n";flush();
function tlv(string $buffer, int &$offset): array {
    $tag=ord($buffer[$offset++]);$size=ord($buffer[$offset++]);
    if($size&128){$n=$size&127;$size=0;while($n--)$size=($size<<8)|ord($buffer[$offset++]);}
    $value=substr($buffer,$offset,$size);$offset+=$size;return [$tag,$value];
}
function enc(int $tag,string $value): string {
    $n=strlen($value);$len=$n<128?chr($n):($n<256?"\x81".chr($n):"\x82".pack('n',$n));
    return chr($tag).$len.$value;
}
while($packet=stream_socket_recvfrom($socket,65535,0,$peer)) {
    $i=0;[, $outer]=tlv($packet,$i);$i=0;[$v,$version]=tlv($outer,$i);[$c,$community]=tlv($outer,$i);[, $pdu]=tlv($outer,$i);
    $i=0;[$r,$request]=tlv($pdu,$i);tlv($pdu,$i);tlv($pdu,$i);[, $list]=tlv($pdu,$i);$i=0;$bindings='';
    while($i<strlen($list)) {
        [, $binding]=tlv($list,$i);$j=0;[$o,$oid]=tlv($binding,$j);
        [$type,$value]=match(bin2hex($oid)) {
            '2b06010201010100'=>[4,'ZTE ZXAN C320 fixture'],
            '2b06010201010300'=>[67,"\x05\x7e\x40"],
            '2b06010201010500'=>[4,'ZXAN-TEST'],
            default=>[128,''],
        };
        $bindings.=enc(48,enc($o,$oid).enc($type,$value));
    }
    $reply=enc(48,enc($v,$version).enc($c,$community).enc(162,enc($r,$request).enc(2,"\0").enc(2,"\0").enc(48,$bindings)));
    stream_socket_sendto($socket,$reply,0,$peer);
}
