<?php
namespace App\Services\Olt;
use App\Models\{Olt,Onu,OltPort};
use phpseclib3\Math\BigInteger;
class OltTrafficService {
    public function rate(?array $previous,array $sample):?array{
        if(!$previous || $previous['entity']!==$sample['entity'] || $sample['at']<=$previous['at'] || $sample['uptime']<$previous['uptime'] || ($sample['discontinuity']??null)!==($previous['discontinuity']??null))return null;
        $elapsed=$sample['at']-$previous['at'];if($elapsed<0.5 || $elapsed>600)return null;
        $result=[];
        foreach(['in','out'] as $key){
            if(!preg_match('/^\d{1,20}$/',(string)$sample[$key])||!preg_match('/^\d{1,20}$/',(string)$previous[$key]))return null;
            $delta=(new BigInteger((string)$sample[$key]))->subtract(new BigInteger((string)$previous[$key]));if($delta->isNegative())return null;
            $result[$key]=(float)$delta->toString()*8/$elapsed;
        }
        return ['download_bps'=>$result['out'],'upload_bps'=>$result['in']];
    }
    public function poll(Olt $olt):array{
        app(OltCapabilities::class)->requireFeature($olt,'traffic');
        return $this->collector($olt)->poll($olt);
    }
    public function collect(Onu|OltPort $entity):array {
        app(OltCapabilities::class)->requireFeature($entity->olt,$entity instanceof Onu?'onu_traffic':'port_traffic');
        return $this->collector($entity->olt)->collect($entity);
    }
    public function coverage(Olt $olt):array { return $this->collector($olt)->coverage($olt); }
    private function collector(Olt $olt):ZteTrafficCollectorService|YotcTrafficCollectorService {
        return app($olt->chassis_type==='C1600'?YotcTrafficCollectorService::class:ZteTrafficCollectorService::class);
    }
}
