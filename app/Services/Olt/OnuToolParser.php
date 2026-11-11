<?php
namespace App\Services\Olt;

class OnuToolParser
{
    public function lines(string $text):array
    {
        // Terminal wrapping can split a profile token in the middle of a name.
        $text=preg_replace('/(vlan-profile\h+\S+)\r?\n(\S+\h+host\b)/','$1$2',$text);
        return array_values(array_filter(array_map(fn($line)=>trim(preg_replace('/\h+/',' ',$line)),preg_split('/\r?\n/',$text))));
    }

    private function token(string $line,string $key,mixed $default=null):mixed
    {
        return preg_match('/ '.preg_quote($key,'/').' ("[^"]*"|\S+)/',$line,$m)?trim($m[1],'"'):$default;
    }

    public function parse(string $interface,string $management):array
    {
        $state=array_fill_keys(['tcont','gem','service','ports','onu_service','wan','tr069','remote'],[]);
        $tcont=[];$gem=[];$services=[];$ports=[];$wan=[];$tr069=[];
        foreach($this->lines($interface)as$line){
            if(preg_match('/^tcont (\d+)\b/',$line,$m)){$id=(int)$m[1];$tcont[$id]=array_merge($tcont[$id]??['id'=>$id],array_filter(['name'=>$this->token($line,'name'),'profile'=>$this->token($line,'profile')],fn($v)=>$v!==null));}
            if(preg_match('/^gemport (\d+)\b/',$line,$m)){$id=(int)$m[1];$gem[$id]=array_merge($gem[$id]??['id'=>$id,'traffic_type'=>'ethernet'],array_filter(['name'=>$this->token($line,'name'),'tcont_id'=>$this->token($line,'tcont'),'profile'=>$this->token($line,'downstream')],fn($v)=>$v!==null));if(str_contains($line,' multicast'))$gem[$id]['traffic_type']='multicast';}
            if(preg_match('/^service-port (\d+)\b/',$line,$m)){$id=(int)$m[1];$row=$services[$id]??['id'=>$id,'description'=>'','status'=>'Aktif'];foreach(['vlan'=>'vlan','user-vlan'=>'user_vlan','vport'=>'gem_id']as$token=>$field)if(($v=$this->token($line,$token))!==null)$row[$field]=is_numeric($v)?(int)$v:$v;if(preg_match('/ description (.+)$/',$line,$v))$row['description']=trim($v[1],'"');if(str_ends_with($line,' disable'))$row['status']='Nonaktif';$services[$id]=$row;}
        }
        foreach($this->lines($management)as$line){
            if(preg_match('/^service (\S+) gemport (\d+)\b/',$line,$m))$state['onu_service'][]=['name'=>$m[1],'gem_id'=>(int)$m[2],'mapping_type'=>str_contains($line,' untag')?'untag':'vlan','vlan'=>$this->token($line,'vlan'),'priority'=>$this->token($line,'cos',0),'uni_type'=>$this->token($line,'iphost')!==null?'iphost':($this->token($line,'ethuni')!==null?'ethuni':'none'),'uni'=>$this->token($line,'iphost',$this->token($line,'ethuni'))];
            if(preg_match('/^vlan (port (\S+)|gemport(-mcast)? (\d+)) mode (\S+)/',$line,$m)){
                $port=str_starts_with($m[1],'port ')?$m[2]:'gem'.(!empty($m[3])?'-mcast':'').'_'.($m[4]??'');
                $ports[$port]=array_merge($ports[$port]??[],['port'=>$port,'type'=>str_starts_with($port,'eth')?'ethernet':(str_starts_with($port,'wifi')?'wlan':'other'),'mode'=>$m[5],'vlan'=>$this->token($line,'vlan',$this->token($line,'def-vlan')),'priority'=>$this->token($line,'pri',$this->token($line,'def-pri',0))]);
            }
            if(preg_match('/^vlan port (\S+) vlan (\S+)/',$line,$m))$ports[$m[1]]=array_merge($ports[$m[1]]??['port'=>$m[1],'type'=>str_starts_with($m[1],'eth')?'ethernet':(str_starts_with($m[1],'wifi')?'wlan':'other'),'mode'=>'none','priority'=>0],['vlan'=>$m[2]]);
            if(preg_match('/^wan-ip(?: (\d+))? (.+)$/',$line,$m)){$id=(int)($m[1]?:1);$wan[$id]=array_merge($wan[$id]??['id'=>$id],array_filter(['mode'=>$this->token($line,'mode'),'ip'=>$this->token($line,'ip-address'),'mask'=>$this->token($line,'mask'),'ip_profile'=>$this->token($line,'ip-profile'),'vlan_profile'=>$this->token($line,'vlan-profile'),'username'=>$this->token($line,'username'),'password'=>$this->token($line,'password'),'host'=>$this->token($line,'host'),'ping'=>$this->token($line,'ping-response'),'traceroute'=>$this->token($line,'traceroute-response')],fn($v)=>$v!==null));}
            if(preg_match('/^tr069-mgmt (\d+)\b/',$line,$m)){$id=(int)$m[1];$tr069[$id]=array_merge($tr069[$id]??['id'=>$id],array_filter(['state'=>$this->token($line,'state'),'acs_url'=>$this->token($line,'acs'),'username'=>$this->token($line,'username'),'password'=>$this->token($line,'password'),'vlan_mode'=>str_contains($line,' untag')?'untag':(str_contains($line,' tag ')?'tag':null),'vlan'=>$this->token($line,'vlan'),'priority'=>$this->token($line,'pri'),'auth'=>$this->token($line,'validate')],fn($v)=>$v!==null));}
            if(preg_match('/^security-mgmt (\d+)\b/',$line,$m))$state['remote'][]=['id'=>(int)$m[1],'state'=>$this->token($line,'state','enable'),'ingress'=>$this->token($line,'ingress-type'),'protocol'=>$this->token($line,'protocol'),'mode'=>$this->token($line,'mode')];
        }
        foreach($wan as&$row)$row+=['ping'=>'disable','host'=>1];unset($row);
        foreach($tr069 as&$row)$row+=['state'=>'lock','auth'=>'disable','vlan_mode'=>'untag','priority'=>0];unset($row);
        $state['tcont']=array_values($tcont);$state['gem']=array_values($gem);$state['service']=array_values($services);$state['ports']=array_values($ports);$state['wan']=array_values($wan);$state['tr069']=array_values($tr069);
        foreach(['tcont','gem']as$key)foreach($state[$key]as&$row){if(isset($row['tcont_id']))$row['tcont_id']=(int)$row['tcont_id'];$row+=['name'=>'','profile'=>''];}unset($row);
        return $state;
    }

    public function profiles(string $text):array
    {
        $rows=[];foreach(preg_split('/(?=Profile name\h*:)/i',$text)as$block){if(!preg_match('/Profile name\h*:\h*(\S+)/i',$block,$m))continue;
            $row=['name'=>$m[1]];foreach(['CVLAN'=>'vlan','Gateway'=>'gateway','Primary DNS'=>'dns','Tag mode'=>'mode']as$key=>$field)if(preg_match('/^\h*'.preg_quote($key,'/').'\h*:\h*(\S+)/im',$block,$v))$row[$field]=$v[1];$rows[]=$row;
        }return $rows;
    }

    public function publicState(array $state):array
    {
        foreach(['wan','tr069']as$section)foreach($state[$section]as&$row){$row['has_password']=!empty($row['password']);unset($row['password']);}unset($row);
        return $state;
    }
}
