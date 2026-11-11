<?php
namespace App\Services;
use App\Models\Olt;
use Illuminate\Support\Facades\{DB,Crypt,RateLimiter};
class SnmpTrapReceiver {
    private function fields(string $bytes):array {
        $rows=[];$p=0;$size=strlen($bytes);
        while($p<$size){if(count($rows)>=256||$p+2>$size)throw new \InvalidArgumentException('Invalid BER');$type=ord($bytes[$p++]);$length=ord($bytes[$p++]);
            if($length&128){$n=$length&127;if(!$n||$n>3||$p+$n>$size)throw new \InvalidArgumentException('Invalid length');$length=0;while($n--)$length=($length<<8)|ord($bytes[$p++]);}
            if($p+$length>$size)throw new \InvalidArgumentException('Truncated BER');$rows[]=[$type,substr($bytes,$p,$length)];$p+=$length;
        }return $rows;
    }
    private function number(string $bytes):int {if(strlen($bytes)>8)throw new \InvalidArgumentException('Integer too long');$n=0;foreach(str_split($bytes)as$b)$n=($n<<8)|ord($b);return $n;}
    private function oid(string $bytes):string {
        $parts=[];$n=0;$open=false;foreach(str_split($bytes)as$b){$v=ord($b);if($n>0x1ffffff||count($parts)>128)throw new \InvalidArgumentException('OID too long');$n=($n<<7)|($v&127);$open=(bool)($v&128);if(!$open){$parts[]=$n;$n=0;}}
        if($open||!$parts)throw new \InvalidArgumentException('Invalid OID');$first=array_shift($parts);$a=min(2,intdiv($first,40));return implode('.',array_merge([$a,$first-40*$a],$parts));
    }
    public function decode(string $packet):array {
        if(strlen($packet)>65507)throw new \InvalidArgumentException('Packet too large');$root=$this->fields($packet);if(count($root)!==1||$root[0][0]!==48)throw new \InvalidArgumentException('Invalid message');$m=$this->fields($root[0][1]);
        if(count($m)!==3||$m[0][0]!==2||$m[1][0]!==4)throw new \InvalidArgumentException('Invalid envelope');$version=$this->number($m[0][1]);$p=$this->fields($m[2][1]);$oid=null;
        if($version===1&&$m[2][0]===167&&count($p)===4){$list=$p[3];}
        elseif($version===0&&$m[2][0]===164&&count($p)===6){$enterprise=$this->oid($p[0][1]);$generic=$this->number($p[2][1]);$specific=$this->number($p[3][1]);$oid=$generic===6?$enterprise.'.0.'.$specific:'1.3.6.1.6.3.1.1.5.'.($generic+1);$list=$p[5];}
        else throw new \InvalidArgumentException('Only SNMP v1/v2c traps supported');
        if($list[0]!==48)throw new \InvalidArgumentException('Invalid varbind list');$bindings=[];
        foreach($this->fields($list[1])as$binding){if($binding[0]!==48)throw new \InvalidArgumentException('Invalid varbind');$pair=$this->fields($binding[1]);if(count($pair)!==2||$pair[0][0]!==6)throw new \InvalidArgumentException('Invalid varbind');$key=$this->oid($pair[0][1]);[$type,$value]=$pair[1];$decoded=$type===6?$this->oid($value):($type===4&&mb_check_encoding($value,'UTF-8')?$value:bin2hex($value));$bindings[]=['oid'=>$key,'type'=>$type,'value'=>$decoded];if($key==='1.3.6.1.6.3.1.1.4.1.0'&&$type===6)$oid=$decoded;}
        if(!$oid)throw new \InvalidArgumentException('Missing trap OID');return ['community'=>$m[1][1],'version'=>$version===0?'v1':'v2c','trap_oid'=>$oid,'bindings'=>$bindings];
    }
    public function accept(string $packet,string $source):?int {
        if(!filter_var($source,FILTER_VALIDATE_IP))return null;
        if(RateLimiter::tooManyAttempts('trap:'.$source,120))return null;RateLimiter::hit('trap:'.$source,60);
        try{$event=$this->decode($packet);}catch(\InvalidArgumentException){return null;}
        $matches=Olt::where('connection_mode','real')->where('ip_address',$source)->get()->filter(fn($o)=>$o->snmp_community_enc&&hash_equals($o->snmp_community_enc,$event['community']));
        if($matches->count()!==1)return null;$olt=$matches->first();unset($event['community']);
        // Payload can contain subscriber data. Encrypt it; never infer ONU state from unknown OIDs.
        return DB::table('snmp_trap_events')->insertGetId(['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'source_ip'=>$source,'trap_oid'=>$event['trap_oid'],'payload'=>Crypt::encryptString(json_encode($event)),'received_at'=>now()]);
    }
}
