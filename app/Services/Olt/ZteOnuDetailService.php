<?php
namespace App\Services\Olt;
use App\Models\{Onu,OnuDetailSnapshot};
use App\Services\NotificationService;
use Illuminate\Support\Facades\{Cache,DB,Log};
use Throwable;

class ZteOnuDetailService {
    public function __construct(private ZteTelnetDriver $driver,private ZteOnuDetailParser $parser){}
    public function interface(Onu $onu):string {
        $index=str_replace('gpon-olt_','',$onu->port->interface_name).':'.$onu->onu_index;
        if(!preg_match('/^\d+\/\d+\/\d+:\d+$/',$index))throw new OltConnectionException('Posisi ONU tidak valid.','invalid_command');
        return 'gpon-onu_'.$index;
    }
    public function refresh(Onu $onu,string $section):array {
        return $this->refreshBatch($onu,[$section])[$section];
    }
    /**
     * Refresh one or more ONU detail sections using a single Telnet session and shared identity/config reads.
     *
     * @param list<string> $sections
     * @return array<string,array<string,mixed>>
     */
    public function refreshBatch(Onu $onu,array $sections):array {
        @set_time_limit(90);
        $sections=array_values(array_unique(array_filter($sections,fn($s)=>in_array($s,['info','wan','ethernet','iphost','wifi','config'],true))));
        if(!$sections)return [];
        if($onu->olt->connection_mode!=='real'){
            $out=[];
            foreach($sections as $section){
                $out[$section]=['source'=>'simulator','data'=>match($section){'info'=>['phase_state'=>$onu->phase_state,'rx_power'=>$onu->rx_power,'olt_rx_power'=>$onu->olt_rx_power],'wan'=>['items'=>$onu->pppoe_user?[['id'=>1,'mode'=>'pppoe','username'=>$onu->pppoe_user,'vlan_id'=>$onu->vlan_id]]:[]],default=>[]},'warnings'=>[],'observed_at'=>null];
            }
            return $out;
        }
        app(OltCapabilities::class)->requireSupported($onu->olt);
        // Put slower/optional OMCI tabs like wifi last so wan/ethernet/iphost always succeed first in a batch.
        if(count($sections)>1){
            $order=['info'=>0,'wan'=>1,'ethernet'=>2,'iphost'=>3,'wifi'=>4,'config'=>5];
            $primary=$sections[0];
            usort($sections,function(string $a,string $b)use($order,$primary){
                if($a===$primary&&$a!=='wifi')return -1;
                if($b===$primary&&$b!=='wifi')return 1;
                return ($order[$a]??9)<=>($order[$b]??9);
            });
        }
        return app(RealOltOperations::class)->readOnly($onu->olt,function()use($onu,$sections){
            $client=$this->driver->client($onu->olt);$i=$this->interface($onu);$sharedWarnings=[];$sectionWarnings=[];$alive=true;
            $idleTimeout=4;$maxDuration=7;
            $read=function(array $commands,?string $group=null,?array &$targetWarnings=null)use($client,&$sectionWarnings,&$alive,$onu,$idleTimeout,$maxDuration){
                if(!$alive)return '';
                $ordered=array_values($commands);
                $cacheKey=$group?'nms:olt-cmd:'.$group.':'.$onu->olt_id:null;
                if($cacheKey&&count($ordered)>1){
                    $pref=Cache::get($cacheKey);
                    if(is_int($pref)&&isset($ordered[$pref])&&$pref>0){
                        $preferred=$ordered[$pref];unset($ordered[$pref]);array_unshift($ordered,$preferred);
                    }
                }
                foreach($ordered as $command){
                    try{
                        $out=$client->command($command,false,$idleTimeout,$maxDuration,true);
                        if($cacheKey&&count($commands)>1){$idx=array_search($command,$commands,true);if($idx!==false)Cache::put($cacheKey,$idx,3600);}
                        return $out;
                    }catch(OltConnectionException $e){
                        if($targetWarnings!==null)$targetWarnings[]=$e->getMessage();
                        else $sectionWarnings[]=$e->getMessage();
                        if($e->reason!=='command_rejected'){$alive=false;break;}
                    }
                }
                return '';
            };
            try{
                $client->connect($onu->olt);
                // Verify physical identity once for the entire batch.
                $detail=$read(['show gpon onu detail-info '.$i],null,$sharedWarnings);$info=$this->parser->info($detail);
                if(($info['sn']??null)===null){
                    $registered=app(ZteOutputParser::class)->configuredOnus($read(['show running-config interface '.$onu->port->interface_name],null,$sharedWarnings),$onu->port->interface_name);
                    $info['sn']=$registered[substr($i,9)]['sn']??null;
                }
                if(strcasecmp($info['sn']??'',$onu->sn)!==0)throw new OltConnectionException('Serial pada posisi ONU belum terverifikasi atau berubah. Jalankan Sync.','identity_changed');
                $isOnline=$info['phase_state']===null||$info['phase_state']==='working';
                $canQueryRemote=!in_array($info['phase_state'],['los','dying-gasp','offline','poweroff','restorefail'],true);
                $config='';
                if(array_intersect($sections,['wan','wifi','config'])){
                    $config=$read(['show onu running config '.$i,'show running-config pon-onu-mng '.$i],'mng-config',$sharedWarnings);
                }
                $existingSnapshots=OnuDetailSnapshot::where('onu_id',$onu->id)->where('source','real')->whereIn('section',$sections)->get()->keyBy('section');
                $results=[];$toPersist=[];$infoData=null;
                foreach($sections as $section){
                    $sectionWarnings=[];
                    if($section==='info'){
                        $existingSnapshot=$existingSnapshots->get('info');
                        $existingData=is_array($existingSnapshot?->payload['data']??null)?$existingSnapshot->payload['data']:[];
                        $hasCachedEquip=$existingSnapshot&&$existingSnapshot->observed_at?->gt(now()->subHours(6))&&(!empty($existingData['reported_type'])||!empty($existingData['software']))&&strcasecmp((string)($existingData['sn']??$onu->sn),(string)$onu->sn)===0;
                        $equip=($isOnline&&!$hasCachedEquip)?$read(['show gpon remote-onu equip '.$i,'show gpon remote-onu equip-info '.$i],'equip'):'';
                        $power=$isOnline?$read(['show pon power attenuation '.$i]):'';
                        $data=$this->parser->info($detail,$equip,$power);
                        if($hasCachedEquip||!$isOnline){
                            $data['reported_type']??=$existingData['reported_type']??null;
                            $data['software']??=$existingData['software']??null;
                        }
                        if($isOnline&&$alive&&$data['rx_power']===null){$out=$read(['show pon power onu-rx '.$i]);if(preg_match('/(?:Rx power\s*[:=]\s*|'.preg_quote(substr($i,9),'/').'\s+)(-?\d+(?:\.\d+)?)/i',$out,$m))$data['rx_power']=(float)$m[1];}
                        if($isOnline&&$alive&&$data['olt_rx_power']===null){$out=$read(['show pon power olt-rx '.$i]);if(preg_match('/(?:Rx power\s*[:=]\s*|'.preg_quote(substr($i,9),'/').'\s+)(-?\d+(?:\.\d+)?)/i',$out,$m))$data['olt_rx_power']=(float)$m[1];}
                        $infoData=$data;
                    }elseif($section==='wan'){
                        $wanIp=$canQueryRemote?$read(['show gpon remote-onu wan-ip '.$i]):'';
                        $data=['items'=>$this->parser->wan($wanIp,$config)];
                    }elseif($section==='ethernet'){
                        $ethOut=$canQueryRemote?$read(['show gpon remote-onu interface eth '.$i,'show gpon remote-onu eth-port '.$i],'ethernet'):'';
                        $data=['items'=>$this->parser->records($ethOut,'ethernet')];
                    }elseif($section==='iphost'){
                        $ipOut=$canQueryRemote?$read(['show gpon remote-onu ip-host '.$i,'show gpon remote-onu ip-host-info '.$i],'iphost'):'';
                        $data=['items'=>$this->parser->records($ipOut,'iphost')];
                    }elseif($section==='wifi'){
                        $ssidOut=$canQueryRemote?$read(['show gpon remote-onu ssid info '.$i,'show gpon remote-onu wifi-info '.$i],'wifi'):'';
                        $wifiIfOut='';
                        $wifiIfRejectKey='nms:olt-cmd-reject:wifi-if:'.$onu->olt_id;
                        $hasWifiHint=(trim($ssidOut)!==''&&!ZteTelnetClient::emptyOnuResponse($ssidOut))||str_contains(strtolower($config),'wifi');
                        if($canQueryRemote&&$alive&&$hasWifiHint&&!Cache::get($wifiIfRejectKey)){
                            try{
                                $wifiIfOut=$client->command('show gpon remote-onu interface wifi '.$i,false,$idleTimeout,$maxDuration,true);
                            }catch(OltConnectionException $e){
                                $sectionWarnings[]=$e->getMessage();
                                if($e->reason==='command_rejected')Cache::put($wifiIfRejectKey,true,900);
                                else $alive=false;
                            }
                        }
                        $data=['items'=>$this->parser->wifi($ssidOut,$config,$wifiIfOut)];
                    }else{
                        $data=['output'=>$this->parser->sanitize($read(['show running-config interface '.$i])."\n".$config)];
                    }
                    $payload=['data'=>$data,'warnings'=>array_values(array_unique(array_merge($sharedWarnings,$sectionWarnings))),'source'=>'real','observed_at'=>now()->toIso8601String()];
                    // Do not replace a usable snapshot with an unsupported/failed empty reading.
                    $meaningful=$section==='info'?count(array_filter($data,fn($v)=>$v!==null))>0:($section==='config'?trim($data['output'])!=='':count($data['items'])>0);
                    if($meaningful){
                        $toPersist[$section]=$payload;
                    }else{
                        $old=$existingSnapshots->get($section);
                        if($old){
                            $payload['data']=$old->payload['data'];
                            $payload['observed_at']=$old->observed_at->toIso8601String();
                            $payload['stale']=true;
                        }else{
                            $payload['observed_at']=null;
                            $payload['unavailable']=true;
                        }
                    }
                    $results[$section]=$payload;
                }
                $statusChanges=[];$opticalChanges=[];
                if($toPersist){
                    DB::transaction(function()use($onu,$toPersist,$infoData,&$statusChanges,&$opticalChanges){
                        $stamp=now();
                        foreach($toPersist as $sec=>$payload){
                            OnuDetailSnapshot::updateOrCreate(['onu_id'=>$onu->id,'section'=>$sec,'source'=>'real'],['payload'=>$payload,'observed_at'=>$stamp]);
                        }
                        if(isset($toPersist['info'])&&is_array($infoData)){
                            $fresh=Onu::whereKey($onu->id)->with('port')->lockForUpdate()->first();
                            if(!$fresh)return;
                            $oldPhase=$fresh->phase_state;
                            $oldRx=$fresh->rx_power!==null?(float)$fresh->rx_power:null;
                            $updates=[];foreach(['phase_state','admin_state','rx_power','olt_rx_power']as$key)if($infoData[$key]!==null)$updates[$key]=$infoData[$key];
                            if(isset($updates['phase_state'])){
                                if($oldPhase!==$updates['phase_state']){
                                    DB::table('onu_status_logs')->insert(['onu_id'=>$fresh->id,'phase_state'=>$updates['phase_state'],'previous_state'=>$oldPhase,'source'=>'real','observed_at'=>$stamp]);
                                    if($updates['phase_state']==='working')$updates['last_online_at']=$stamp;
                                    elseif($oldPhase==='working')$updates['last_offline_at']=$stamp;
                                    if($oldPhase!==null)$statusChanges[]=['onu'=>$fresh,'port'=>$fresh->port,'old_phase'=>$oldPhase,'new_phase'=>$updates['phase_state']];
                                }
                                $updates['state_observed_at']=$stamp;
                            }
                            if(isset($updates['rx_power'])||isset($updates['olt_rx_power'])){$updates['optical_observed_at']=$stamp;$fresh->opticalLogs()->create(['rx_power'=>$infoData['rx_power'],'olt_rx_power'=>$infoData['olt_rx_power'],'observation_source'=>'real','calculated_at'=>$stamp]);}
                            if($updates)$fresh->update($updates);
                            if((isset($updates['rx_power'])||isset($updates['olt_rx_power']))&&$fresh->rx_power!==null&&$oldRx!==(float)$fresh->rx_power){
                                $opticalChanges[]=['onu'=>$fresh,'port'=>$fresh->port,'old_rx'=>$oldRx,'new_rx'=>(float)$fresh->rx_power];
                            }
                        }
                    });
                    if($statusChanges||$opticalChanges){
                        try{app(NotificationService::class)->dispatchBatch($onu->olt,$statusChanges,$opticalChanges);}
                        catch(Throwable $e){Log::warning('Notification dispatch from ONU detail failed',['olt_id'=>$onu->olt_id,'onu_id'=>$onu->id,'error'=>$e->getMessage()]);}
                    }
                }
                return $results;
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

    /**
     * Perform a single-command live phase_state check on a ZTE ONU (for Ping RTO double-check).
     *
     * @return array{phase_state:?string,live:bool,down_cause:?string}
     */
    public function probeLiveState(Onu $onu): array
    {
        $onu->loadMissing(['olt', 'port']);
        if (! $onu->olt || $onu->olt->connection_mode !== 'real' || $onu->olt->chassis_type === 'C1600' || ! $onu->port) {
            $fresh = $onu->fresh();

            return ['phase_state' => $fresh?->phase_state ?? $onu->phase_state, 'live' => false, 'down_cause' => null];
        }

        try {
            return app(RealOltOperations::class)->readOnly($onu->olt, function () use ($onu) {
                $client = $this->driver->client($onu->olt);
                $i = $this->interface($onu);
                try {
                    $client->connect($onu->olt);
                    $detail = $client->command('show gpon onu detail-info '.$i, false, 5, 8, true);
                    $info = $this->parser->info($detail);
                    if (($info['sn'] ?? null) === null) {
                        $registered = app(ZteOutputParser::class)->configuredOnus(
                            $client->command('show running-config interface '.$onu->port->interface_name, false, 5, 8, true),
                            $onu->port->interface_name
                        );
                        $info['sn'] = $registered[substr($i, 9)]['sn'] ?? null;
                    }
                    if (strcasecmp($info['sn'] ?? '', $onu->sn) !== 0 || empty($info['phase_state'])) {
                        $fresh = $onu->fresh();

                        return ['phase_state' => $fresh?->phase_state ?? $onu->phase_state, 'live' => false, 'down_cause' => $info['last_down_cause'] ?? null];
                    }

                    DB::transaction(function () use ($onu, $info) {
                        $locked = Onu::whereKey($onu->id)->lockForUpdate()->first();
                        if (! $locked) {
                            return;
                        }
                        $stamp = now();
                        $oldPhase = $locked->phase_state;
                        $updates = ['phase_state' => $info['phase_state'], 'state_observed_at' => $stamp];
                        if ($oldPhase !== $info['phase_state']) {
                            DB::table('onu_status_logs')->insert([
                                'onu_id' => $locked->id,
                                'phase_state' => $info['phase_state'],
                                'previous_state' => $oldPhase,
                                'source' => 'real',
                                'observed_at' => $stamp,
                            ]);
                            if ($info['phase_state'] === 'working') {
                                $updates['last_online_at'] = $stamp;
                            } elseif ($oldPhase === 'working') {
                                $updates['last_offline_at'] = $stamp;
                            }
                        }
                        $locked->update($updates);
                    });

                    return [
                        'phase_state' => $info['phase_state'],
                        'live' => true,
                        'down_cause' => $info['last_down_cause'] ?? null,
                    ];
                } finally {
                    $client->close();
                }
            });
        } catch (Throwable) {
            $fresh = $onu->fresh();

            return ['phase_state' => $fresh?->phase_state ?? $onu->phase_state, 'live' => false, 'down_cause' => null];
        }
    }
}
