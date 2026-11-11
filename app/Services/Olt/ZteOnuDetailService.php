<?php
namespace App\Services\Olt;
use App\Models\{Onu,OnuDetailSnapshot};
use Illuminate\Support\Facades\DB;

class ZteOnuDetailService {
    public function __construct(private ZteTelnetDriver $driver,private ZteOnuDetailParser $parser){}
    public function interface(Onu $onu):string {
        $index=str_replace('gpon-olt_','',$onu->port->interface_name).':'.$onu->onu_index;
        if(!preg_match('/^\d+\/\d+\/\d+:\d+$/',$index))throw new OltConnectionException('Posisi ONU tidak valid.','invalid_command');
        return 'gpon-onu_'.$index;
    }
    public function refresh(Onu $onu,string $section):array {
        @set_time_limit(90);
        if($onu->olt->connection_mode!=='real')return ['source'=>'simulator','data'=>match($section){'info'=>['phase_state'=>$onu->phase_state,'rx_power'=>$onu->rx_power,'olt_rx_power'=>$onu->olt_rx_power],'wan'=>['items'=>$onu->pppoe_user?[['id'=>1,'mode'=>'pppoe','username'=>$onu->pppoe_user,'vlan_id'=>$onu->vlan_id]]:[]],default=>[]},'warnings'=>[],'observed_at'=>null];
        app(OltCapabilities::class)->requireSupported($onu->olt);
        return app(RealOltOperations::class)->readOnly($onu->olt,function()use($onu,$section){
            $client=$this->driver->client($onu->olt);$i=$this->interface($onu);$warnings=[];$alive=true;$success=0;
            $read=function(array $commands)use($client,&$warnings,&$alive,&$success){
                if(!$alive)return '';
                foreach($commands as $command)try{$out=$client->command($command,false,15,20,true);$success++;return $out;}catch(OltConnectionException $e){$warnings[]=$e->getMessage();if($e->reason!=='command_rejected'){$alive=false;break;}}
                return '';
            };
            try{
                $client->connect($onu->olt);
                // Verify physical identity before publishing details against a local ONU.
                $detail=$read(['show gpon onu detail-info '.$i]);$info=$this->parser->info($detail);
                if(($info['sn']??null)===null){
                    $registered=app(ZteOutputParser::class)->configuredOnus($read(['show running-config interface '.$onu->port->interface_name]),$onu->port->interface_name);
                    $info['sn']=$registered[substr($i,9)]['sn']??null;
                }
                if(strcasecmp($info['sn']??'',$onu->sn)!==0)throw new OltConnectionException('Serial pada posisi ONU belum terverifikasi atau berubah. Jalankan Sync.','identity_changed');
                $config='';
                if(in_array($section,['wan','wifi','config'],true))$config=$read(['show onu running config '.$i,'show running-config pon-onu-mng '.$i]);
                $data=match($section){
                    'info'=>$this->parser->info($detail,$read(['show gpon remote-onu equip '.$i,'show gpon remote-onu equip-info '.$i]),$read(['show pon power attenuation '.$i])),
                    'wan'=>['items'=>$this->parser->wan($read(['show gpon remote-onu wan-ip '.$i]),$config)],
                    'ethernet'=>['items'=>$this->parser->records($read(['show gpon remote-onu interface eth '.$i,'show gpon remote-onu eth-port '.$i]),'ethernet')],
                    'iphost'=>['items'=>$this->parser->records($read(['show gpon remote-onu ip-host '.$i,'show gpon remote-onu ip-host-info '.$i]),'iphost')],
                    'wifi'=>['items'=>$this->parser->wifi($read(['show gpon remote-onu ssid info '.$i,'show gpon remote-onu wifi-info '.$i]),$config,$read(['show gpon remote-onu interface wifi '.$i]))],
                    'config'=>['output'=>$this->parser->sanitize($read(['show running-config interface '.$i])."\n".$config)],
                };
                if($section==='info'&&$alive&&$data['rx_power']===null){$out=$read(['show pon power onu-rx '.$i]);if(preg_match('/(?:Rx power\s*[:=]\s*|'.preg_quote(substr($i,9),'/').'\s+)(-?\d+(?:\.\d+)?)/i',$out,$m))$data['rx_power']=(float)$m[1];}
                if($section==='info'&&$alive&&$data['olt_rx_power']===null){$out=$read(['show pon power olt-rx '.$i]);if(preg_match('/(?:Rx power\s*[:=]\s*|'.preg_quote(substr($i,9),'/').'\s+)(-?\d+(?:\.\d+)?)/i',$out,$m))$data['olt_rx_power']=(float)$m[1];}
                $payload=['data'=>$data,'warnings'=>array_values(array_unique($warnings)),'source'=>'real','observed_at'=>now()->toIso8601String()];
                // Do not replace a usable snapshot with an unsupported/failed empty reading.
                $meaningful=$section==='info'?count(array_filter($data,fn($v)=>$v!==null))>0:($section==='config'?trim($data['output'])!=='':count($data['items'])>0);
                if($meaningful){
                    DB::transaction(function()use($onu,$section,$payload,$data){
                        OnuDetailSnapshot::updateOrCreate(['onu_id'=>$onu->id,'section'=>$section,'source'=>'real'],['payload'=>$payload,'observed_at'=>now()]);
                        if($section==='info'){
                            $updates=[];foreach(['phase_state','admin_state','rx_power','olt_rx_power']as$key)if($data[$key]!==null)$updates[$key]=$data[$key];
                            if(isset($updates['phase_state'])){if($onu->phase_state!==$updates['phase_state'])DB::table('onu_status_logs')->insert(['onu_id'=>$onu->id,'phase_state'=>$updates['phase_state'],'previous_state'=>$onu->phase_state,'source'=>'real','observed_at'=>now()]);$updates['state_observed_at']=now();}
                            if(isset($updates['rx_power'])||isset($updates['olt_rx_power'])){$updates['optical_observed_at']=now();$onu->opticalLogs()->create(['rx_power'=>$data['rx_power'],'olt_rx_power'=>$data['olt_rx_power'],'observation_source'=>'real','calculated_at'=>now()]);}
                            if($updates)$onu->update($updates);
                        }
                    });
                }else{$old=OnuDetailSnapshot::where(['onu_id'=>$onu->id,'section'=>$section,'source'=>'real'])->first();if($old){$payload['data']=$old->payload['data'];$payload['observed_at']=$old->observed_at->toIso8601String();$payload['stale']=true;}else{$payload['observed_at']=null;$payload['unavailable']=true;}}
                return $payload;
            }finally{$client->close();}
        });
    }
    public function configure(Onu $onu,string $kind,array $data):void {
        app(OltCapabilities::class)->requireFeature($onu->olt,'onu_configuration');
        $i=$this->interface($onu);$client=$this->driver->client($onu->olt);
        try{
            $client->connect($onu->olt);$info=$this->parser->info($client->command('show gpon onu detail-info '.$i));
            if(strcasecmp($info['sn']??'',$onu->sn)!==0)throw new OltConnectionException('Serial ONU tidak cocok; konfigurasi dibatalkan.','identity_changed');
            $client->addSecrets(array_filter([$data['password']??null]));
            $id=(int)($data['id']??1);
            $extra=[];
            if($kind==='wifi'&&!empty($data['password'])){
                $extra[]='ssid auth wpa wifi_0/'.$id.' key '.$data['password'];
            }
            $command=match($kind){
                'wan'=>!empty($data['delete'])?'no wan-ip '.$id:'wan-ip '.$id.' mode pppoe username '.$data['username'].' password '.$data['password'].' vlan-profile '.$data['vlan_profile'].' host '.($data['host']??1),
                'ethernet'=>'interface eth eth_0/'.$id.' state '.($data['enabled']?'unlock':'lock'),
                'wifi'=>'ssid ctrl wifi_0/'.$id.' name '.$data['name'],
                'factory'=>'restore factory',
                'description'=>'description '.$data['description'],
            };
            $client->command('configure terminal');$client->command(($kind==='description'?'interface ':'pon-onu-mng ').$i);
            // Never send a complete write followed by "?\r\n": ZTE can execute it.
            // The explicit user confirmation authorizes exactly this command sequence.
            $commands=[$command,...$extra];
            try{
                foreach($commands as $candidate){$out=$client->command($candidate,true);if(preg_match(ZteTelnetClient::CONFIRM,$out))$client->command('yes');}$client->command('end');
                if($kind!=='factory'){
                    $readback=$client->command($kind==='description'?'show running-config interface '.$i:'show onu running config '.$i,false,5,10,true);
                    $expected=str_contains($readback,$command);
                    if($kind==='wan'){
                        $row=collect($this->parser->wan('',$readback))->firstWhere('id',$id);
                        $expected=!empty($data['delete'])?$row===null:($row!==null&&($row['username']??null)===$data['username']&&($row['password']??null)===$data['password']&&($row['vlan_profile']??null)===$data['vlan_profile']&&(int)($row['host']??0)===(int)($data['host']??1));
                    }
                    if($kind==='wifi'){$row=collect($this->parser->wifi('',$readback))->firstWhere('id',$id);$expected=($row['name']??null)===$data['name']&&(empty($data['password'])||($row['password']??null)===$data['password']);}
                    if($kind==='ethernet'){$row=collect($this->parser->records($client->command('show gpon remote-onu interface eth '.$i),'ethernet'))->firstWhere('id',$id);$expected=strtolower($row['admin']??'')===($data['enabled']?'unlock':'lock');}
                    if(!$expected)throw new OltConnectionException('Perubahan belum terverifikasi dari konfigurasi OLT.','configuration_uncertain');
                }
            }catch(OltConnectionException $e){throw new OltConnectionException('Hasil perubahan perlu diperiksa sebelum diulang. '.$e->getMessage(),'configuration_uncertain');}
            OnuDetailSnapshot::where('onu_id',$onu->id)->delete();
        }finally{$client->close();}
    }
}
