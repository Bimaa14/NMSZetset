<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use phpseclib3\Math\BigInteger;

class TrafficHistory
{
    // Aggregate in SQL: PHP receives one summary and at most 150 chart rows.
    public function read(Builder $base, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::transaction(fn()=>$this->aggregate($base,$from,$to));
    }

    private function aggregate(Builder $base, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $driver=DB::connection()->getDriverName();
        // Database timestamps are stored as application-local wall time, without an offset.
        $fromStamp=CarbonImmutable::parse($from->format('Y-m-d H:i:s'),'UTC')->timestamp;
        $epoch=match($driver){'sqlite'=>"CAST(strftime('%s',recorded_at) AS INTEGER)",'pgsql'=>'EXTRACT(EPOCH FROM recorded_at)',default=>'UNIX_TIMESTAMP(recorded_at)'};
        if($driver==='sqlite')DB::connection()->getPdo()->sqliteCreateFunction('nms_counter_delta',static function($a,$b){
            if($a===null||$b===null)return null;
            $delta=(new BigInteger((string)$a))->subtract(new BigInteger((string)$b));
            return $delta->isNegative()?null:(float)$delta->toString();
        },2);
        $previous=(clone $base)->where('recorded_at','<',$from)->orderByDesc('recorded_at')->orderByDesc('id')->first(['id']);
        $rows=(clone $base)->where(fn($q)=>$q->whereBetween('recorded_at',[$from,$to])->when($previous,fn($q)=>$q->orWhere('id',$previous->id)))->select('*')->selectRaw("$epoch AS stamp");
        $window=DB::query()->fromSub($rows,'samples')->select('*');
        foreach(['stamp','source','rx_bps','tx_bps','rx_bytes','tx_bytes'] as $column)$window->selectRaw("LAG($column) OVER (ORDER BY recorded_at,id) AS prev_$column");
        $measured=DB::query()->fromSub($window,'history')->where('recorded_at','>=',$from)->select('*');
        $dt='(stamp-prev_stamp)';
        $overlap="(CASE WHEN prev_stamp < ? THEN stamp-? ELSE $dt END)";
        foreach(['rx','tx'] as $key){
            $delta=$driver==='sqlite'?"nms_counter_delta({$key}_bytes,prev_{$key}_bytes)":"(CAST({$key}_bytes AS DECIMAL(20,0))-CAST(prev_{$key}_bytes AS DECIMAL(20,0)))";
            $volume="CASE WHEN $dt>0 AND $dt<=600 AND source=prev_source THEN CASE WHEN {$key}_bytes IS NOT NULL AND prev_{$key}_bytes IS NOT NULL THEN CASE WHEN $delta>=0 THEN $delta*1.0/$dt END ELSE ({$key}_bps+prev_{$key}_bps)/16.0 END END";
            $measured->selectRaw("($volume)*$overlap AS {$key}_volume",[$fromStamp,$fromStamp]);
            $measured->selectRaw("CASE WHEN ($volume) IS NOT NULL THEN $overlap ELSE 0 END AS {$key}_seconds",[$fromStamp,$fromStamp]);
        }
        $summary=DB::query()->fromSub($measured,'measured')->selectRaw('COUNT(*) AS count, MIN(stamp) AS first_stamp, MAX(stamp) AS last_stamp');
        foreach(['rx','tx'] as $key)$summary->selectRaw("MAX({$key}_bps) AS {$key}_peak, MIN({$key}_bps) AS {$key}_minimum, SUM({$key}_volume) AS {$key}_volume, SUM({$key}_seconds) AS {$key}_seconds");
        $summary=$summary->first();
        $last=(clone $base)->whereBetween('recorded_at',[$from,$to])->orderByDesc('recorded_at')->orderByDesc('id')->first();
        $stats=[];
        foreach(['rx','tx'] as $key){$seconds=(float)($summary->{$key.'_seconds'}??0);$volume=$seconds?(float)$summary->{$key.'_volume'}:null;
            $stats[$key]=['current'=>$last?->{$key.'_bps'}===null?null:(float)$last->{$key.'_bps'},'peak'=>$summary->{$key.'_peak'}===null?null:(float)$summary->{$key.'_peak'},'minimum'=>$summary->{$key.'_minimum'}===null?null:(float)$summary->{$key.'_minimum'},'average'=>$seconds?$volume*8/$seconds:null,'volume_bytes'=>$volume,'covered_seconds'=>$seconds];
        }
        $width=max(1,ceil((($summary->last_stamp??0)-($summary->first_stamp??0)+1)/150));
        $bucket=$driver==='sqlite'?"CAST(($epoch - ?)/? AS INTEGER)":"FLOOR(($epoch - ?)/?)";
        $points=(clone $base)->whereBetween('recorded_at',[$from,$to])->selectRaw('MIN(recorded_at) AS recorded_at, AVG(rx_bps) AS rx_bps, AVG(tx_bps) AS tx_bps, MIN(source) AS source')->groupByRaw($bucket,[$summary->first_stamp??0,$width])->orderBy('recorded_at')->get()->map(fn($p)=>['recorded_at'=>CarbonImmutable::parse($p->recorded_at)->toIso8601String(),'rx_bps'=>$p->rx_bps===null?null:(float)$p->rx_bps,'tx_bps'=>$p->tx_bps===null?null:(float)$p->tx_bps,'source'=>$p->source])->all();
        // Only times before the first recorded sample get a visual zero baseline.
        // Keep synthetic buckets out of measured points, statistics and exports.
        $baseline=!$previous&&count($points)&&CarbonImmutable::parse($points[0]['recorded_at'])->greaterThan($from)
            ? ['from'=>$from->toIso8601String(),'to'=>$points[0]['recorded_at'],'rx_bps'=>0,'tx_bps'=>0,'buckets'=>120]
            : null;
        return ['points'=>$points,'chart_baseline'=>$baseline,'statistics'=>$stats,'sample_count'=>(int)$summary->count,'from'=>$from->toIso8601String(),'to'=>$to->toIso8601String(),'last_recorded_at'=>$last?CarbonImmutable::parse($last->recorded_at)->toIso8601String():null,'source'=>$last?->source,'volume_note'=>'Volume terukur; jeda lebih dari 10 menit dan reset counter tidak diinterpolasi.'];
    }
}
