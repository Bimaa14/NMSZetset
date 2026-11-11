<?php
namespace App\Services\Olt;

use App\Models\{Onu,OnuDetailSnapshot};
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class OnuToolService
{
    public function __construct(private ZteTelnetDriver $driver,private OnuToolParser $parser){}

    public function connection(Onu $onu):string
    {
        return hash('sha256',json_encode([$onu->olt->only(['connection_mode','ip_address','telnet_port','cli_transport','telnet_user','ssh_host_key_sha256']),$onu->olt->getRawOriginal('telnet_pass_enc'),$onu->sn,$onu->olt_id,$onu->olt_port_id,$onu->onu_index]));
    }

    public function session(Onu $onu,callable $action):mixed
    {
        app(OltCapabilities::class)->requireSupported($onu->olt);
        if($onu->olt->connection_mode!=='real')throw new OltConnectionException('Tool ini memerlukan OLT dalam mode real.','configuration');
        $client=$this->driver->client($onu->olt);
        try{$client->connect($onu->olt);$i=app(ZteOnuDetailService::class)->interface($onu);
            $identity=app(ZteOnuDetailParser::class)->info($client->command('show gpon onu detail-info '.$i,false,5,12,true));
            if(strcasecmp($identity['sn']??'',$onu->sn)!==0)throw new OltConnectionException('Serial ONU tidak cocok. Jalankan Sync sebelum konfigurasi.','identity_changed');
            return $action($client,$i);
        }finally{$client->close();}
    }

    public function read(CliClient $client,string $i):array
    {
        $interface=$client->command('show running-config interface '.$i,false,5,15,true);
        try{$management=$client->command('show onu running config '.$i,false,5,15,true);}
        catch(OltConnectionException $e){if($e->reason!=='command_rejected')throw $e;$management=$client->command('show running-config pon-onu-mng '.$i,false,5,15,true);}
        foreach([[$interface,'interface'],[$management,'pon-onu-mng']]as[$text,$context]){
            if(!preg_match('/^\h*'.preg_quote($context,'/').'\h+'.preg_quote($i,'/').'\h*$/m',$text))throw new OltConnectionException('Blok konfigurasi ONU belum dikenali. Data terakhir dipertahankan.','parse_failed');
        }
        return ['state'=>$this->parser->parse($interface,$management),'fingerprint'=>hash('sha256',json_encode([$this->parser->lines($interface),$this->parser->lines($management)])),'backup'=>['interface'=>$interface,'management'=>$management]];
    }

    public function profiles(Onu $onu,CliClient $client):array
    {
        return Cache::remember('nms:onu-tool-profiles:'.$onu->tenant_id.':'.$onu->olt_id.':'.$this->connection($onu),300,function()use($client){
            $profiles=[];$warnings=[];
            foreach(['tcont'=>'show gpon profile tcont','traffic'=>'show gpon profile traffic','vlan'=>'show gpon onu profile vlan','ip'=>'show gpon onu profile ip']as$key=>$command){
                try{$profiles[$key]=$this->parser->profiles($client->command($command,false,5,15,true));}
                catch(OltConnectionException $e){if($e->reason!=='command_rejected')throw $e;$profiles[$key]=[];$warnings[]='Daftar profil '.$key.' tidak didukung firmware ini.';}
            }return $profiles+['warnings'=>$warnings];
        });
    }

    public function publish(Onu $onu,array $state,array $profiles):array
    {
        $payload=['state'=>$this->parser->publicState($state),'profiles'=>$profiles,'source'=>'real','observed_at'=>now()->toIso8601String()];
        OnuDetailSnapshot::updateOrCreate(['onu_id'=>$onu->id,'section'=>'configuration_tool','source'=>'real'],['payload'=>$payload,'observed_at'=>now()]);
        return $payload;
    }

    private function invalid(string $message,string $field='id'):never {throw ValidationException::withMessages([$field=>$message]);}

    public function prepare(array $data,array $state,array $profiles,string $i):array
    {
        foreach(['id','tcont_id','gem_id','port_id','vlan','priority','host']as$field)if(isset($data[$field])&&$data[$field]!=='')$data[$field]=(int)$data[$field];
        $kind=$data['kind'];$action=$data['action'];$delete=$action==='delete';$id=$data['id']??null;
        $key=match($kind){'onu_service'=>'name','ports'=>'port',default=>'id'};
        if($kind==='ports')$data['port']=($data['port_type']==='ethernet'?'eth':'wifi').'_0/'.$data['port_id'];
        $identity=$data[$key];$existing=collect($state[$kind])->first(fn($row)=>(string)$row[$key]===(string)$identity);
        if($action==='create'&&$existing)$this->invalid('ID atau nama sudah terpakai. Gunakan Edit.',$key);
        if($action!=='create'&&!$existing)$this->invalid('Konfigurasi tujuan sudah tidak tersedia. Muat ulang.',$key);
        if($kind==='tcont'&&!$delete&&$action==='create'&&count($state['tcont'])>=8)$this->invalid('Maksimal delapan T-CONT per ONU.');
        if($delete&&$kind==='tcont'&&collect($state['gem'])->contains(fn($g)=>(int)($g['tcont_id']??0)===$id))$this->invalid('T-CONT masih dipakai GEM Port. Lepaskan mapping tersebut terlebih dahulu.');
        if($delete&&$kind==='gem'&&(collect($state['service'])->contains(fn($r)=>(int)($r['gem_id']??0)===$id)||collect($state['onu_service'])->contains(fn($r)=>(int)$r['gem_id']===$id)))$this->invalid('GEM Port masih dipakai Service atau ONU Service.');
        if(!$delete){
            if($kind==='gem'&&!collect($state['tcont'])->contains('id',(int)$data['tcont_id']))$this->invalid('Pilih T-CONT yang tersedia.','tcont_id');
            if(in_array($kind,['service','onu_service'],true)&&!collect($state['gem'])->contains('id',(int)$data['gem_id']))$this->invalid('Pilih GEM Port yang tersedia.','gem_id');
            foreach(match($kind){'tcont'=>['profile'=>'tcont'],'gem'=>empty($data['profile'])?[]:['profile'=>'traffic'],'wan'=>array_filter(['vlan_profile'=>'vlan','ip_profile'=>$data['mode']==='static'?'ip':null]),default=>[]}as$field=>$group){
                if(!collect($profiles[$group]??[])->contains('name',$data[$field]))$this->invalid('Profil belum tersedia di OLT. Muat ulang daftar profil.',$field);
            }
            if($kind==='wan'&&$data['mode']==='static'){$bits=sprintf('%032b',ip2long($data['mask']));if(!preg_match('/^1+0*$/',$bits))$this->invalid('Subnet mask harus berupa mask IPv4 berurutan.','mask');}
            if($kind==='onu_service'&&$data['uni_type']==='iphost'&&(!ctype_digit((string)$data['uni'])||(int)$data['uni']<1||(int)$data['uni']>255))$this->invalid('IP Host harus 1–255.','uni');
            if($kind==='onu_service'&&$data['uni_type']==='ethuni'&&!preg_match('/^eth_0\/[1-4]$/',(string)$data['uni']))$this->invalid('Pilih port ETH 1–4.','uni');
            if(in_array($kind,['wan','tr069'],true)&&empty($data['password']))$data['password']=$existing['password']??null;
            if(($kind==='wan'&&$data['mode']==='pppoe'||$kind==='tr069'&&$data['auth']==='basic')&&empty($data['password']))$this->invalid('Password wajib untuk konfigurasi baru.','password');
            if(!empty($data['password'])&&!preg_match('/^[A-Za-z0-9_@.()+!#%=:,\/-]{1,64}$/D',$data['password']))$this->invalid('Password tersimpan memerlukan format lain. Isi password baru dengan karakter yang didukung.','password');
            if(in_array($kind,['tcont','gem'],true)&&empty($data['name']))$data['name']=$existing['name']??'';
        }
        $context=in_array($kind,['tcont','gem','service'],true)?'interface ':'pon-onu-mng ';
        $commands=[];$expected=[];
        if($delete){$commands=[match($kind){'tcont'=>'no tcont '.$id,'gem'=>'no gemport '.$id,'service'=>'no service-port '.$id,'ports'=>'no vlan port '.$data['port'].' mode','onu_service'=>'no service '.$data['name'],'wan'=>'no wan-ip '.$id,'tr069'=>'no tr069-mgmt '.$id.' acs','remote'=>'no security-mgmt '.$id}];}
        else switch($kind){
            case 'tcont':$commands[]='tcont '.$id.(!empty($data['name'])?' name '.$data['name']:'').' profile '.$data['profile'];$expected=['profile'=>$data['profile']];if($data['name']!=='')$expected['name']=$data['name'];break;
            case 'gem':$commands[]='gemport '.$id.(!empty($data['name'])?' name '.$data['name']:'').' tcont '.$data['tcont_id'];if(!empty($data['profile']))$commands[]='gemport '.$id.' traffic-limit downstream '.$data['profile'];$expected=['tcont_id'=>$data['tcont_id']];if($data['name']!=='')$expected['name']=$data['name'];if(!empty($data['profile']))$expected['profile']=$data['profile'];break;
            case 'service':$commands[]='service-port '.$id.' vport '.$data['gem_id'].' user-vlan '.$data['vlan'].' vlan '.$data['vlan'];if(!empty($data['description']))$commands[]='service-port '.$id.' description '.$data['description'];$expected=['gem_id'=>$data['gem_id'],'vlan'=>$data['vlan']];if(!empty($data['description']))$expected['description']=$data['description'];break;
            case 'ports':$port=$data['port'];$mode=$data['mode'];
                if($mode==='none')$commands[]='no vlan port '.$port.' mode';
                elseif($mode==='tag')$commands[]='vlan port '.$port.' mode tag vlan '.$data['vlan'].' pri '.$data['priority'];
                elseif($mode==='hybrid')$commands[]='vlan port '.$port.' mode hybrid def-vlan '.$data['vlan'].' def-pri '.$data['priority'];
                elseif($mode==='trunk'){$commands[]='vlan port '.$port.' mode trunk';$commands[]='vlan port '.$port.' vlan '.$data['vlan'];}
                else $commands[]='vlan port '.$port.' mode transparent';
                $expected=['mode'=>$mode];if(in_array($mode,['tag','hybrid','trunk'],true))$expected['vlan']=$data['vlan'];if(in_array($mode,['tag','hybrid'],true))$expected['priority']=$data['priority'];break;
            case 'onu_service':$uni=$data['uni_type']==='none'?'':' '.$data['uni_type'].' '.$data['uni'];$commands[]='service '.$data['name'].' gemport '.$data['gem_id'].$uni.' cos '.$data['priority'].($data['mapping_type']==='vlan'?' vlan '.$data['vlan']:' untag');$expected=['gem_id'=>$data['gem_id'],'priority'=>$data['priority'],'mapping_type'=>$data['mapping_type'],'uni_type'=>$data['uni_type']];if($data['mapping_type']==='vlan')$expected['vlan']=$data['vlan'];if($data['uni_type']!=='none')$expected['uni']=$data['uni'];break;
            case 'wan':$command='wan-ip '.$id.' mode '.$data['mode'];if($data['mode']==='static')$command.=' ip-profile '.$data['ip_profile'].' ip-address '.$data['ip'].' mask '.$data['mask'];if($data['mode']==='pppoe')$command.=' username '.$data['username'].' password '.$data['password'];$commands[]=$command.' vlan-profile '.$data['vlan_profile'].' host '.$data['host'];$commands[]='wan-ip '.$id.' ping-response '.($data['ping']?'enable':'disable').' traceroute-response disable';$expected=['mode'=>$data['mode'],'vlan_profile'=>$data['vlan_profile'],'host'=>$data['host'],'ping'=>$data['ping']?'enable':'disable'];foreach(match($data['mode']){'static'=>['ip','mask','ip_profile'],'pppoe'=>['username','password'],default=>[]}as$field)$expected[$field]=$data[$field];break;
            case 'remote':$commands[]='security-mgmt '.$id.' state '.$data['state'].' mode forward ingress-type '.$data['ingress'].' protocol '.$data['protocol'];$expected=['state'=>$data['state'],'ingress'=>$data['ingress'],'protocol'=>$data['protocol']];break;
            case 'tr069':$commands[]='tr069-mgmt '.$id.' '.($data['vlan_mode']==='tag'?'tag pri '.$data['priority'].' vlan '.$data['vlan']:'untag');$commands[]='tr069-mgmt '.$id.' acs '.$data['acs_url'].' validate '.($data['auth']==='basic'?'basic username '.$data['username'].' password '.$data['password']:'disable');$commands[]='tr069-mgmt '.$id.' state '.$data['state'];$expected=['vlan_mode'=>$data['vlan_mode'],'state'=>$data['state'],'acs_url'=>$data['acs_url'],'auth'=>$data['auth']];if($data['vlan_mode']==='tag')$expected+=['vlan'=>$data['vlan'],'priority'=>$data['priority']];if($data['auth']==='basic')$expected+=['username'=>$data['username'],'password'=>$data['password']];break;
        }
        return ['commands'=>['configure terminal',$context.$i,...$commands,'end'],'expected'=>$expected,'key'=>$key,'identity'=>$identity,'input'=>$data];
    }

    public function verified(array $plan,array $after):bool
    {
        if(($plan['input']['kind']??null)==='restore'){foreach($plan['restore_plans']as$part)if(!$this->verified($part,$after))return false;return true;}
        $kind=$plan['input']['kind'];$row=collect($after[$kind])->first(fn($row)=>(string)$row[$plan['key']]===(string)$plan['identity']);
        if($plan['input']['action']==='delete')return match($kind){'tr069'=>empty($row['acs_url']),'ports'=>$row===null||($row['mode']??'none')==='none',default=>$row===null};
        if($kind==='ports'&&$plan['input']['mode']==='none')return $row===null||($row['mode']??'none')==='none';
        if(!$row)return false;
        foreach($plan['expected']as$key=>$value)if((string)($row[$key]??'')!==(string)$value)return false;
        return true;
    }
}
