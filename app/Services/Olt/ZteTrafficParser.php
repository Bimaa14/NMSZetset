<?php
namespace App\Services\Olt;

class ZteTrafficParser
{
    /** Interface counters are viewed from the OLT: output is customer download. */
    public function parse(string $text): array
    {
        $result=['rx_bps'=>null,'tx_bps'=>null,'rx_bytes'=>null,'tx_bytes'=>null];
        foreach(['Input'=>'tx','Output'=>'rx'] as $direction=>$key){
            if(preg_match('/^\h*'.$direction.' rate\h*:?\h*([\d,.]+)\h*([kKmMgG]?)([bB])ps/m',$text,$m)){
                $result[$key.'_bps']=(float)str_replace(',','',$m[1])*pow(1000,$m[2]===''?0:strpos('kmg',strtolower($m[2]))+1)*($m[3]==='B'?8:1);
            }
            if(preg_match('/^\h*'.$direction.'\h*:\h*\R(?:(?!^\h*(?:Input|Output)\h*:).)*?\b(?:PassBytes|Bytes)\h*:\h*([\d,]+)/ms',$text,$m)
                ||preg_match('/^\h*'.$direction.'\h+[^\r\n]*?([\d,]+)\h+bytes\b/im',$text,$m))$result[$key.'_bytes']=str_replace(',','',$m[1]);
        }
        return $result;
    }
}
