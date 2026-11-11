<?php
namespace App\Services\Olt;

/** Parsers accept observed values only. Missing/unsupported fields stay null. */
class ZteOnuDetailParser {
    public function fields(string $text):array {
        $fields=[];
        foreach(preg_split('/\r?\n/',$text) as $line)if(preg_match('/^\h*([A-Za-z][A-Za-z0-9 _().\/-]*?)\h*:\h*(.*?)\h*$/',$line,$m))$fields[strtolower(trim($m[1]))]=trim($m[2]);
        return $fields;
    }
    private function pick(array $fields,array $keys):?string {foreach($keys as $key)if(isset($fields[$key])&&!in_array(strtolower($fields[$key]),['','n/a','unknown','--','-'],true))return $fields[$key];return null;}
    private function number(?string $value):?float{return $value!==null&&preg_match('/-?\d+(?:\.\d+)?/',$value,$m)?(float)$m[0]:null;}
    public function cleanDescription(?string $value):?string {
        if(!$value)return null;
        if(preg_match('/^\$*zone_.*?_descr_(.*?)_extid_.*$/i',$value,$m))return trim(str_replace('_',' ',$m[1]))?:null;
        if(preg_match('/^\$*zone_/i',$value))return null;
        return trim($value);
    }
    public function info(string $detail,string $equipment='',string $power=''):array {
        $f=$this->fields($detail);$e=$this->fields($equipment);$p=$this->fields($power);
        $phase=strtolower(preg_replace('/[^a-z]/i','',$f['phase state']??''));
        $data=['name'=>$this->pick($f,['name']),'sn'=>$this->pick($f,['serial number','serial']),
            'phase_state'=>match($phase){'working','online'=>'working','dyinggasp'=>'dying-gasp','los'=>'los','offline'=>'offline','syncmib'=>'sync-mib','logging'=>'logging','auth'=>'auth',default=>null},
            'admin_state'=>$this->pick($f,['admin state']),'configured_type'=>$this->pick($f,['type']),
            'reported_type'=>$this->pick($e,['equipment id','equipment type','onu type','type','model']),
            'software'=>$this->pick($e,['software version','software version 1','main software version','version','version of main software','sw version']),
            'distance_m'=>$this->number($this->pick($f,['onu distance','distance'])),
            'description'=>$this->cleanDescription($this->pick($f,['description'])),
            'last_online_at'=>null,'last_offline_at'=>null,'last_down_cause'=>$this->pick($f,['last down cause','last offline reason']),
            'rx_power'=>$this->number($this->pick($p,['onu rx power','rx power','onu rx optical power'])),
            'tx_power'=>$this->number($this->pick($p,['onu tx power','tx power','onu tx optical power'])),
            'olt_rx_power'=>$this->number($this->pick($p,['olt rx power','olt rx optical power']))];
        // ZTE attenuation output: up OLT Rx / ONU Tx, down OLT Tx / ONU Rx.
        if(preg_match('/^\h*up\h+Rx\s*:\s*(-?\d+(?:\.\d+)?)\s*\(dBm\)\s+Tx\s*:\s*(-?\d+(?:\.\d+)?)/mi',$power,$m)){$data['olt_rx_power']=(float)$m[1];$data['tx_power']=(float)$m[2];}
        if(preg_match('/^\h*down\h+Tx\s*:\s*-?\d+(?:\.\d+)?\s*\(dBm\)\s+Rx\s*:\s*(-?\d+(?:\.\d+)?)/mi',$power,$m))$data['rx_power']=(float)$m[1];
        preg_match_all('/^\h*\d+\h+(\d{4}-\d{2}-\d{2}\h+\d{2}:\d{2}:\d{2})\h+(\d{4}-\d{2}-\d{2}\h+\d{2}:\d{2}:\d{2})\h*(.*?)\h*$/m',$detail,$rows,PREG_SET_ORDER);
        foreach($rows as $row){foreach([1=>'last_online_at',2=>'last_offline_at'] as $index=>$key)if(!str_starts_with($row[$index],'0000-')&&($data[$key]===null||$row[$index]>$data[$key])){$data[$key]=$row[$index];if($index===2&&$row[3]!=='')$data['last_down_cause']=$row[3];}}
        return $data;
    }
    public function records(string $text,string $kind):array {
        $pattern=match($kind){'ethernet'=>'(?:Ethernet\s+port|Eth(?:ernet)?[- ]port|Port|Interface)','wifi'=>'(?:SSID(?:\s+(?:index|id))?|WiFi\s+interface|Interface)','wan'=>'(?:WAN(?:\s+(?:index|id))?|WAN-IP|IP\s+index|Host(?:\s+id)?)',default=>'(?:IP\s+host|Host(?:\s+(?:index|id))?)'};
        $records=[];$current=null;
        foreach(preg_split('/\r?\n/',$text) as $line){
            if(preg_match('/^\h*'.$pattern.'\h*[:=]\h*(?:(?:eth|wifi)_\d+\/)?(\d+)\h*$/i',$line,$m)){$current=(int)$m[1];$records[$current]=['id'=>$current];continue;}
            if($kind==='ethernet'&&preg_match('/^\h*(?:eth_\d+\/)?(\d+)\h+(enable|disable|lock|unlock)\h+(up|down|active|inactive)\h+(\S+)(?:\h+(\S+))?/i',$line,$m)){$records[(int)$m[1]]=['id'=>(int)$m[1],'admin'=>strtolower($m[2]),'operational'=>strtolower($m[3]),'speed'=>$m[4],'duplex'=>$m[5]??null];continue;}
            if($current!==null&&preg_match('/^\h*([A-Za-z][A-Za-z0-9 _().\/-]*?)\h*:\h*(.*?)\h*$/',$line,$m))$records[$current][strtolower(trim($m[1]))]=trim($m[2]);
        }
        return array_values(array_map(function($f)use($kind){
            $p=fn(...$keys)=>$this->pick($f,$keys);
            return match($kind){
                'ethernet'=>['id'=>$f['id'],'admin'=>$p('admin','admin state','admin status'),'operational'=>$p('operational','operational state','operate status','oper state','link status','oper status'),'speed'=>$p('speed','current speed','speed status','actual speed'),'duplex'=>$p('duplex','duplex status'),'auto_negotiation'=>$p('auto negotiation','auto-negotiation','speed config'),'details'=>$f],
                'wifi'=>['id'=>$f['id'],'name'=>$p('name','ssid name'),'admin'=>$p('admin state','admin status','admin','status'),'operational'=>$p('operate status','operational state'),'security'=>$p('security','authentication algorithm','authentication','auth mode'),'encryption'=>$p('encryption','encrypt'),'max_users'=>$this->number($p('max users','max user','maximum users')),'connected'=>$this->number($p('connected','current users','associated stations','users')),'visibility'=>isset($f['hide'])?(strtolower($f['hide'])==='disable'?'Terlihat':'Tersembunyi'):$p('visibility','broadcast'),'isolation'=>$p('isolation','user isolation'),'password'=>str_contains(strtolower($p('password','wpa passphrase','key')??''),'onu default')?null:$p('password','wpa passphrase','key')],
                default=>['id'=>$f['id'],'mode'=>$p('mode','ip mode','connection type','dhcp enable'),'ip'=>$p('current ip','current ip address','ip address','ip','ip addres'),'mask'=>$p('current mask','subnet mask','mask'),'gateway'=>$p('current gateway','gateway','default gateway'),'dns'=>$p('current primary dns','dns','primary dns','primary dns server'),'status'=>$p('status','connection status','wan status'),'username'=>$p('username','user name'),'password'=>$p('password'),'profile'=>$p('wan profile','profile'),'omci'=>$p('omci','host name','name'),'vlan_id'=>$this->number($p('cvlan','vlan'))]
            };
        },$records));
    }
    public function wan(string $output,string $config):array {
        $config=preg_replace('/(vlan-profile\h+\S+)\r?\n([A-Za-z0-9_-]+\h+host\b)/','$1$2',$config);
        $rows=[];foreach($this->records($output,'wan') as $row)$rows[$row['id']]=$row;
        foreach(preg_split('/\r?\n/',$config) as $line)if(preg_match('/^\h*wan-ip\h+(\d+)\h+mode\h+(\S+)(.*)$/i',$line,$m)){
            $id=(int)$m[1];$r=$rows[$id]??['id'=>$id];$r['mode']=strtolower($m[2]);
            foreach(['username'=>'username','password'=>'password','vlan-profile'=>'vlan_profile','wan-profile'=>'profile','host'=>'host'] as $token=>$field)if(preg_match('/\b'.preg_quote($token,'/').'\h+("[^"]*"|\S+)/',$m[3],$v))$r[$field]=trim($v[1],'"');
            $rows[$id]=$r;
        }return array_values($rows);
    }
    public function wifi(string $output,string $config,string $interfaces=''):array {
        $rows=[];foreach($this->records($output,'wifi') as $row)$rows[$row['id']]=$row;
        foreach(preg_split('/\r?\n/',$config) as $line)if(preg_match('/^\h*ssid\h+ctrl\h+wifi_0\/(\d+)\h+(.+)$/i',$line,$m)){
            $id=(int)$m[1];$r=$rows[$id]??['id'=>$id];foreach(['name'=>'name','admin'=>'admin']as$token=>$field)if(preg_match('/\b'.$token.'\h+("[^"]*"|\S+)/',$m[2],$v))$r[$field]=trim($v[1],'"');$rows[$id]=$r;
        }
        foreach(preg_split('/\r?\n/',$config) as $line)if(preg_match('/^\h*ssid\h+auth\h+(?:wpa\h+)?wifi_0\/(\d+)\h+(.+)/i',$line,$m)){
            $id=(int)$m[1];$row=$rows[$id]??['id'=>$id];
            if(preg_match('/\b(wpa2?-psk|wpa-wpa2-psk)\b/i',$m[2],$v))$row['security']=$v[1];
            if(preg_match('/\bencrypt\h+(\S+)/i',$m[2],$v))$row['encryption']=$v[1];
            if(preg_match('/\b(?:key|password)\h+("[^"]*"|\S+)/i',$m[2],$v))$row['password']=trim($v[1],'"');
            $rows[$id]=$row;
        }
        foreach($this->records($interfaces,'wifi') as $state){$id=$state['id'];$rows[$id]=array_merge($rows[$id]??['id'=>$id],array_filter(['admin'=>$state['admin'],'operational'=>$state['operational']],fn($v)=>$v!==null));}
        return array_values($rows);
    }
    public function sanitize(string $text):string {
        $text=preg_replace('/\b(password|passwd|community|secret|key|passphrase)\h*:\h*[^\r\n]*/i','$1: [REDACTED]',$text);
        return preg_replace('/\b(password|passwd|community|secret|key|passphrase)\h+(?!exchange\b|update\b)("[^"]*"|[^\s:]+)/i','$1 [REDACTED]',$text);
    }
}
