<?php
namespace App\Services\Olt;

use App\Models\{Olt,Onu,OltPort};
use Illuminate\Support\Facades\{Cache,DB};

class ZteTrafficCollectorService
{
    public function __construct(private ZteSnmpDriver $snmp,private ZteTelnetDriver $driver,private ZteTrafficParser $parser,private OltTrafficService $rates){}
    public function interface(Onu|OltPort $entity):string{
        $name=$entity instanceof Onu?str_replace('gpon-olt_','gpon-onu_',$entity->port->interface_name).':'.$entity->onu_index:$entity->interface_name;
        if(!preg_match('/^gpon-(?:onu_\d+\/\d+\/\d+:\d+|olt_\d+\/\d+\/\d+)$/D',$name))throw new OltConnectionException('Interface traffic tidak valid.','configuration');
        return $name;
    }
    private function connectionKey(Olt $olt):string{return 'traffic:'.hash('sha256',$olt->id.'|'.$olt->ip_address.'|'.$olt->telnet_port.'|'.$olt->snmp_port.'|'.$olt->snmp_community_enc);}
    public function collect(Onu|OltPort $entity):array{
        app(OltCapabilities::class)->requireFeature($entity->olt,'traffic');
        return app(RealOltOperations::class)->readOnly($entity->olt,fn()=>$this->collectUnlocked($entity));
    }
    public function collectUnlocked(Onu|OltPort $entity, mixed $client=null):array{
        app(OltCapabilities::class)->requireFeature($entity->olt,'traffic');
        $olt=$entity->olt;$name=$this->interface($entity);
        if($olt->connection_mode!=='real')throw new OltConnectionException('Traffic simulator belum memiliki counter. Tidak ada traffic buatan.','unavailable');
        $key=$this->connectionKey($olt);$recent=Cache::get($key.':recent:'.$entity->getTable().':'.$entity->id.':'.($entity->sn??'').':'.$name);
        if($recent&&microtime(true)-$recent['at']<1)return $recent['data'];
        if($olt->snmp_enabled&&!Cache::has($key.':snmp-down')){
            try{
                $probe=clone $olt;$probe->connection_timeout=1;
                $names=Cache::remember($key.':names',300,fn()=>$this->snmp->walk($probe,'.1.3.6.1.2.1.31.1.1.1.1'));
                $index=null;foreach($names as $oid=>$label)if(trim((string)$label," \t\r\n\"")===$name&&preg_match('/\.(\d+)$/',$oid,$m)){$index=$m[1];break;}
                if($index!==null){
                    $v=$this->snmp->get($probe,['.1.3.6.1.2.1.31.1.1.1.6.'.$index,'.1.3.6.1.2.1.31.1.1.1.10.'.$index,'.1.3.6.1.2.1.31.1.1.1.19.'.$index,'.1.3.6.1.2.1.1.3.0']);
                    if(!ctype_digit((string)$v[0])||!ctype_digit((string)$v[1])||!is_numeric($v[3]))throw new OltConnectionException('Counter SNMP tidak valid.','snmp_failed');
                    return $this->persist($entity,['rx_bps'=>null,'tx_bps'=>null,'rx_bytes'=>(string)$v[1],'tx_bytes'=>(string)$v[0]],'snmp',(float)$v[3],(string)$v[2]);
                }
            }catch(OltConnectionException){Cache::put($key.':snmp-down',true,300);}
        }
        $own=$client===null;$client??=$this->driver->client($olt);
        try{
            if($own)$client->connect($olt);
            $data=$this->parser->parse($client->command('show interface '.$name,false,8,12));
            if(count(array_filter($data,fn($v)=>$v!==null))===0)throw new OltConnectionException('Perintah show interface '.$name.' tidak memberikan rate/counter yang dikenali.','traffic_unavailable');
            return $this->persist($entity,$data,'cli');
        }finally{if($own)$client->close();}
    }
    public function persist(Onu|OltPort $entity,array $data,string $source,float $uptime=0,?string $discontinuity=null):array{
        // Network reads are independent, but a counter baseline must have a single writer.
        $lock=Cache::lock('nms:traffic-sample:'.$entity->olt->tenant_id.':'.$entity->getTable().':'.$entity->id,30);
        if(!$lock->get())throw new OltConnectionException('Sampel traffic sedang disimpan.','read_busy');
        try{return $this->persistSample($entity,$data,$source,$uptime,$discontinuity);}finally{$lock->release();}
    }
    private function persistSample(Onu|OltPort $entity,array $data,string $source,float $uptime,?string $discontinuity):array{
        $name=$this->interface($entity);$key=$this->connectionKey($entity->olt).':'.$source.':'.$name.':'.($entity->sn??'port');$at=microtime(true);
        $sample=['entity'=>$entity->getTable().':'.$entity->id.':'.$name,'at'=>$at,'uptime'=>$uptime,'discontinuity'=>$discontinuity,'in'=>$data['tx_bytes'],'out'=>$data['rx_bytes']];
        $previous=Cache::get($key);$rate=$this->rates->rate($previous,$sample);
        if(($rate['download_bps']??0)>0||$data['rx_bps']===null)$data['rx_bps']=$rate['download_bps']??null;
        if(($rate['upload_bps']??0)>0||$data['tx_bps']===null)$data['tx_bps']=$rate['upload_bps']??null;
        $stamp=now();$row=$data+['tenant_id'=>$entity->olt->tenant_id,'olt_id'=>$entity->olt_id,'source'=>$source,'recorded_at'=>$stamp];
        DB::transaction(function()use($entity,$row,$data,$stamp){
            if($entity instanceof Onu)DB::table('onu_traffic_logs')->insert($row+['onu_id'=>$entity->id,'download_bps'=>$data['rx_bps'],'upload_bps'=>$data['tx_bps'],'observed_at'=>$stamp]);
            else DB::table('port_traffic_logs')->insert($row+['olt_port_id'=>$entity->id]);
            $entity->update(['download_bps'=>$data['rx_bps'],'upload_bps'=>$data['tx_bps'],'traffic_observed_at'=>$stamp]);
        });
        Cache::put($key,$sample,86400);$row['recorded_at']=$stamp->toIso8601String();
        Cache::put($this->connectionKey($entity->olt).':recent:'.$entity->getTable().':'.$entity->id.':'.($entity->sn??'').':'.$name,['at'=>$at,'data'=>$row],5);
        return $row;
    }
    /** Rotate a bounded batch to avoid starving inventory and live requests on large OLTs. */
    public function poll(Olt $olt):array{
        app(OltCapabilities::class)->requireFeature($olt,'traffic');
        $entities=$olt->ports()->current()->get()->concat($olt->onus()->current()->with('port')->where('phase_state','working')->get())->values();
        if($entities->isEmpty())return ['Belum ada interface untuk traffic.'];
        $key='traffic:cursor:'.$olt->id;$cursor=(int)Cache::get($key,0);$start=microtime(true);$warnings=[];$client=null;$count=0;
        try{
            if($olt->snmp_enabled&&!Cache::has($this->connectionKey($olt).':snmp-down')){
                try{
                    $probe=clone $olt;$probe->connection_timeout=1;
                    $names=Cache::remember($this->connectionKey($olt).':names',300,fn()=>$this->snmp->walk($probe,'.1.3.6.1.2.1.31.1.1.1.1'));
                    $indices=[];foreach($names as$oid=>$label)if(preg_match('/\.(\d+)$/',$oid,$m))$indices[trim((string)$label," \t\r\n\"")]=$m[1];
                    while($count<$entities->count()&&microtime(true)-$start<20){
                        $batch=[];$oids=[];
                        for($j=0;$j<20&&$count+$j<$entities->count();$j++){
                            $entity=$entities[($cursor+$count+$j)%$entities->count()];$entity->setRelation('olt',$olt);$index=$indices[$this->interface($entity)]??null;
                            if($index===null)throw new OltConnectionException('Interface tidak tersedia di IF-MIB.','snmp_failed');
                            $batch[]=$entity;foreach([6,10,19]as$field)$oids[]='.1.3.6.1.2.1.31.1.1.1.'.$field.'.'.$index;
                        }
                        $oids[]='.1.3.6.1.2.1.1.3.0';$values=$this->snmp->get($probe,$oids);$uptime=array_pop($values);
                        if(count($values)!==count($batch)*3||!is_numeric($uptime))throw new OltConnectionException('Respons counter SNMP tidak lengkap.','snmp_failed');
                        foreach($batch as$j=>$entity){[$in,$out,$reset]=array_slice($values,$j*3,3);if(!ctype_digit((string)$in)||!ctype_digit((string)$out))throw new OltConnectionException('Counter SNMP tidak valid.','snmp_failed');
                            $this->persist($entity,['rx_bps'=>null,'tx_bps'=>null,'rx_bytes'=>(string)$out,'tx_bytes'=>(string)$in],'snmp',(float)$uptime,(string)$reset);$count++;
                        }
                    }
                    $olt->update(['traffic_observed_at'=>now()]);return [];
                }catch(OltConnectionException $e){Cache::put($this->connectionKey($olt).':snmp-down',true,300);$warnings[]='SNMP batch tidak lengkap; pembacaan dilanjutkan lewat CLI.';}
            }
            // A single CLI session serves the batch when SNMP is disabled or in cooldown.
            if(!$olt->snmp_enabled||Cache::has($this->connectionKey($olt).':snmp-down')){$client=$this->driver->client($olt);$client->connect($olt);}
            while($count<min(40,$entities->count())&&microtime(true)-$start<20){
                $entity=$entities[($cursor+$count)%$entities->count()];$entity->setRelation('olt',$olt);$count++;
                try{$this->collectUnlocked($entity,$client);}catch(OltConnectionException $e){$warnings[]=$e->getMessage();if(!in_array($e->reason,['command_rejected','traffic_unavailable']))break;}
            }
        }finally{$client?->close();Cache::put($key,($cursor+$count)%$entities->count(),86400);}
        $olt->update(['traffic_observed_at'=>now()]);return array_values(array_unique($warnings));
    }

    public function coverage(Olt $olt):array {
        $onus=$olt->onus()->current()->where('phase_state','working');$ports=$olt->ports()->current();$target=max(15,(int)config('olt.traffic_interval',60));
        $total=(clone$onus)->count()+(clone$ports)->count();$fresh=0;$oldest=null;
        foreach([$onus,$ports]as$q){$fresh+=(clone$q)->where('traffic_observed_at','>=',now()->subSeconds($target))->whereNotNull('download_bps')->whereNotNull('upload_bps')->count();$at=(clone$q)->min('traffic_observed_at');if($at&&(!$oldest||$at<$oldest))$oldest=$at;}
        return ['target_seconds'=>$target,'interfaces'=>$total,'fresh'=>$fresh,'overdue'=>max(0,$total-$fresh),'oldest_sample_at'=>$oldest,'complete'=>$fresh===$total];
    }
}
