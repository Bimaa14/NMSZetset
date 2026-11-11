<?php
namespace App\Services;
use App\Models\Onu;
use Illuminate\Support\Facades\{DB,Cache};
class OpticalAnalysis {
    public function forTenant(int $tenant):array {
        return Cache::remember('nms:optical-analysis:'.$tenant,60,function()use($tenant){
            $base=DB::table('onu_optical_logs')->select('onu_id','observation_source')->selectRaw('AVG(rx_power) as baseline_rx, COUNT(*) as samples')->whereNotNull('rx_power')->whereBetween('calculated_at',[now()->subDay(),now()->subMinutes(15)])->groupBy('onu_id','observation_source');
            $rows=Onu::current()->where('onus.tenant_id',$tenant)->leftJoinSub($base,'baseline',fn($j)=>$j->on('baseline.onu_id','=','onus.id')->on('baseline.observation_source','=','onus.observation_source'))->get(['onus.id','onus.olt_id','onus.olt_port_id','onus.rx_power','onus.optical_observed_at','baseline.baseline_rx','baseline.samples']);
            $items=[];$ports=[];
            foreach($rows as$r){$stale=!$r->optical_observed_at||\Carbon\Carbon::parse($r->optical_observed_at)->lt(now()->subMinutes(15));$delta=$r->rx_power!==null&&$r->baseline_rx!==null?round($r->rx_power-$r->baseline_rx,2):null;
                $degraded=!$stale&&$r->samples>=3&&$delta!==null&&$delta<=-3;
                $items[$r->id]=['baseline_rx'=>$r->baseline_rx===null?null:round($r->baseline_rx,2),'baseline_samples'=>(int)$r->samples,'delta_db'=>$delta,'optical_stale'=>$stale,'degraded'=>$degraded,'severity'=>$degraded?($delta<=-6?'critical':'warning'):null,'correlated_count'=>0];
                if($degraded)$ports[$r->olt_port_id][]=$r->id;
            }
            foreach($ports as$ids)foreach($ids as$id)$items[$id]['correlated_count']=count($ids);
            return ['items'=>$items,'degraded_ids'=>array_keys(array_filter($items,fn($r)=>$r['degraded'])),'correlated_pons'=>count(array_filter($ports,fn($ids)=>count($ids)>=2)),'calculated_at'=>now()->toIso8601String(),'baseline_hours'=>24,'recent_minutes'=>15,'drop_db'=>3];
        });
    }
}
