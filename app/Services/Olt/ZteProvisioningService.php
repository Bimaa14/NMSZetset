<?php
namespace App\Services\Olt;
use App\Models\{Olt,ServiceProfile};
class ZteProvisioningService {
    public function __construct(private ZteTelnetDriver $driver,private ZteOutputParser $parser) {}
    public function unconfigured(string $output): array {
        if(trim($output)==='' || preg_match('/No related information|No\s+unconfigured|ONU\s+Number\s*:\s*0/i',$output))return [];
        $rows=[];
        foreach(preg_split('/\r?\n/',$output) as $line) {
            if(preg_match('/(?:gpon-onu_)?(\d+)\/(\d+)\/(\d+):(\d+)\s+([A-Z]{4}[A-F0-9]{8})\s+(\S+)/i',$line,$m))
                $rows[]=['frame'=>(int)$m[1],'slot'=>(int)$m[2],'port'=>(int)$m[3],'detected_index'=>(int)$m[4],'sn'=>strtoupper($m[5]),'state'=>$m[6]];
        }
        if(!$rows && !preg_match('/OnuIndex.*Sn.*State/is',$output))throw new OltConnectionException('Format ONU belum terdaftar belum dikenali. Periksa output Web CLI.','parse_failed');
        return $rows;
    }
    public function discover(Olt $olt): array {
        return $this->withClient($olt,fn(CliClient $client)=>$this->unconfigured($client->command('show gpon onu uncfg')));
    }
    public function commands(ServiceProfile $profile,array $p,?string $user=null,?string $password=null): array {
        $port=$p['frame'].'/'.$p['slot'].'/'.$p['port'];$interface=$port.':'.$p['onu_index'];
        $commands=['configure terminal','interface gpon-olt_'.$port,'onu '.$p['onu_index'].' type '.$profile->onu_type.' sn '.$p['sn'],'exit','interface gpon-onu_'.$interface,'name '.$p['name']];
        if(in_array($profile->mode,['bridge','pppoe'],true)) {
            array_push($commands,'tcont 1 profile '.$profile->upstream_profile,'gemport 1 tcont 1','gemport 1 traffic-limit downstream '.$profile->downstream_profile,'service-port 1 vport 1 user-vlan '.$profile->vlan_id.' vlan '.$profile->vlan_id,'exit','pon-onu-mng gpon-onu_'.$interface,'service internet gemport 1 vlan '.$profile->vlan_id);
            if($profile->mode==='pppoe')$commands[]='wan-ip 1 mode pppoe username '.($user??'{{pppoe_user}}').' password '.($password??'{{pppoe_password}}').' vlan-profile '.$profile->wan_profile.' host 1';
        }
        if($profile->mode==='custom') {
            $values=['{{interface}}'=>$interface,'{{port}}'=>$port,'{{onu_index}}'=>(string)$p['onu_index'],'{{sn}}'=>$p['sn'],'{{name}}'=>$p['name'],'{{pppoe_user}}'=>$user??'{{pppoe_user}}','{{pppoe_password}}'=>$password??'{{pppoe_password}}'];
            foreach($profile->commands??[] as $command)$commands[]=strtr($command,$values);
        }
        $commands[]='end';return $commands;
    }
    public function check(Olt $olt,array $p): void {
        $this->withClient($olt,function(CliClient $client) use($p) {$this->preflight($client,$p);});
    }
    private function preflight(CliClient $client,array $p): void {
        $detected=$this->unconfigured($client->command('show gpon onu uncfg'));
        $match=array_filter($detected,fn($o)=>$o['frame']===$p['frame']&&$o['slot']===$p['slot']&&$o['port']===$p['port']&&$o['sn']===$p['sn']);
        if(!$match)throw new OltConnectionException('ONU tidak lagi terdeteksi pada PON tujuan. Pindai ulang sebelum mendaftarkan.','configuration');
        $port='gpon-olt_'.$p['frame'].'/'.$p['slot'].'/'.$p['port'];
        $configured=$this->parser->configuredOnus($client->command('show running-config interface '.$port),$port);
        if(isset($configured[$p['frame'].'/'.$p['slot'].'/'.$p['port'].':'.$p['onu_index']]))throw new OltConnectionException('ID ONU sudah terpakai pada perangkat. Pilih ID lain.','configuration');
    }
    public function apply(Olt $olt,ServiceProfile $profile,array $p,?string $user,?string $password): string {
        if($profile->mode==='custom'&&!($profile->verification??[]))throw new OltConnectionException('Profil custom memerlukan baris verifikasi hasil sebelum diterapkan.','configuration');
        return $this->withClient($olt,function(CliClient $client) use($profile,$p,$user,$password) {
            $client->addSecrets(array_filter([$password])); $this->preflight($client,$p); $writes=false;
            try {
                foreach($this->commands($profile,$p,$user,$password) as $command){if(str_starts_with($command,'onu '))$writes=true;$client->command($command);}
                $port='gpon-olt_'.$p['frame'].'/'.$p['slot'].'/'.$p['port'];
                $rows=$this->parser->configuredOnus($client->command('show running-config interface '.$port),$port);
                $key=$p['frame'].'/'.$p['slot'].'/'.$p['port'].':'.$p['onu_index'];
                if(($rows[$key]['sn']??null)!==$p['sn'])throw new OltConnectionException('Pendaftaran belum dapat diverifikasi dari konfigurasi OLT.','provision_uncertain');
                $this->verifyServices($client,$profile,$p,$user,$password);
                return 'Pendaftaran ONU terverifikasi di OLT. Inventaris dijadwalkan untuk diperbarui.';
            }catch(OltConnectionException $e){
                throw new OltConnectionException(($writes?'Konfigurasi mungkin sudah diterapkan sebagian. Periksa perangkat sebelum mencoba ulang. ':'').$e->getMessage(),$writes?'provision_uncertain':$e->reason);
            }
        });
    }
    private function verifyServices(CliClient $client,ServiceProfile $profile,array $p,?string $user,?string $password):void {
        $i='gpon-onu_'.$p['frame'].'/'.$p['slot'].'/'.$p['port'].':'.$p['onu_index'];
        $interface=$client->command('show running-config interface '.$i,false,5,15,true);
        $parser=app(OnuToolParser::class);$lines=$parser->lines($interface);
        if(!in_array('interface '.$i,$lines,true)||!in_array('name '.$p['name'],$lines,true))throw new OltConnectionException('Nama atau blok interface ONU belum sesuai hasil provisioning.','provision_uncertain');
        if($profile->mode==='registration')return;
        $read=app(OnuToolService::class)->read($client,$i);$state=$read['state'];
        $matches=function(string $section,array $expected)use($state):bool{
            foreach($state[$section]??[]as$row){$match=true;foreach($expected as$key=>$value)if((string)($row[$key]??'')!==(string)$value){$match=false;break;}if($match)return true;}return false;
        };
        if(in_array($profile->mode,['bridge','pppoe'],true)){
            $ok=$matches('tcont',['id'=>1,'profile'=>$profile->upstream_profile])
                &&$matches('gem',['id'=>1,'tcont_id'=>1,'profile'=>$profile->downstream_profile])
                &&$matches('service',['id'=>1,'gem_id'=>1,'vlan'=>$profile->vlan_id,'user_vlan'=>$profile->vlan_id,'status'=>'Aktif'])
                &&$matches('onu_service',['name'=>'internet','gem_id'=>1,'vlan'=>$profile->vlan_id]);
            if($profile->mode==='pppoe')$ok=$ok&&$matches('wan',['id'=>1,'mode'=>'pppoe','username'=>$user,'password'=>$password,'vlan_profile'=>$profile->wan_profile,'host'=>1]);
            if(!$ok)throw new OltConnectionException('Readback T-CONT/GEM/VLAN/service/WAN belum cocok. Hasil provisioning perlu diperiksa; jangan ulangi otomatis.','provision_uncertain');
        }else{
            $values=['{{sn}}'=>$p['sn'],'{{name}}'=>$p['name'],'{{onu_index}}'=>(string)$p['onu_index'],'{{pppoe_user}}'=>$user??'','{{pppoe_password}}'=>$password??''];
            foreach($profile->verification as$rule){$actual=$parser->lines($read['backup'][$rule['scope']]);$expected=strtr($rule['line'],$values);$found=in_array($expected,$actual,true);if($found===($rule['absent']??false))throw new OltConnectionException('Konfigurasi custom belum memenuhi baris verifikasi. Periksa hasil sebelum mengulang.','provision_uncertain');}
        }
    }
    private function withClient(Olt $olt,callable $callback): mixed {
        app(OltCapabilities::class)->requireSupported($olt);
        $client=$this->driver->client($olt);try{$client->connect($olt);return $callback($client);}finally{$client->close();}
    }
}
