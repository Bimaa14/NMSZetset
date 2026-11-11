<?php
namespace App\Services\Olt;
use App\Jobs\PollOlt;
use App\Models\{Olt,OltOperation,User};
use Illuminate\Support\Facades\{Cache,DB};
class OltOperationService {
    public function enqueue(Olt $olt,string $kind='sync',?User $actor=null,?string $ip=null): OltOperation {
        return DB::transaction(function() use($olt,$kind,$actor,$ip) {
            $fresh=Olt::whereKey($olt->id)->lockForUpdate()->firstOrFail();
            app(OltCapabilities::class)->requireOperation($fresh,$kind);
            $active=OltOperation::where('active_olt_id',$fresh->id)->first();
            if($active && $active->status==='running' && $active->heartbeat_at?->lt(now()->subSeconds(420))) {
                $active->update(['status'=>'failed','active_olt_id'=>null,'completed_at'=>now(),'error'=>'Worker terhenti. Snapshot terakhir dipertahankan.']); $active=null;
            }
            if(!$active)$active=$this->activateNext($fresh);
            $same=OltOperation::where('olt_id',$fresh->id)->where('kind',$kind)->whereIn('status',['queued','running'])->first();
            if($same)return $same;
            $op=OltOperation::create(['tenant_id'=>$fresh->tenant_id,'olt_id'=>$fresh->id,'active_olt_id'=>$active?null:$fresh->id,'actor_id'=>$actor?->id,'actor_type'=>$actor?'User':'System','ip_address'=>$ip,'kind'=>$kind,'stage'=>$kind==='sync'?'inventory':$kind,'warnings'=>[],'is_initial_sync'=>!$fresh->last_sync_at&&in_array($kind,['sync','inventory'],true)]);
            if(!$active)$this->dispatchQueued($op);
            return $op->refresh();
        });
    }
    public function activateNext(Olt $olt):?OltOperation {
        return DB::transaction(function()use($olt){
            Olt::whereKey($olt->id)->lockForUpdate()->firstOrFail();
            $active=OltOperation::where('active_olt_id',$olt->id)->first();if($active)return $active;
            $next=OltOperation::where('olt_id',$olt->id)->where('status','queued')->whereNull('active_olt_id')->oldest('id')->first();
            if($next){$next->update(['active_olt_id'=>$olt->id]);$this->dispatchQueued($next);}
            return $next;
        });
    }
    public function tick(): int {
        $this->recoverQueued();
        OltOperation::where('status','running')->where('heartbeat_at','<',now()->subSeconds(420))->update(['status'=>'failed','active_olt_id'=>null,'completed_at'=>now(),'error'=>'Worker terhenti. Snapshot terakhir dipertahankan.']);
        foreach(Olt::whereIn('id',OltOperation::where('status','queued')->whereNull('active_olt_id')->select('olt_id'))->get() as $pendingOlt)$this->activateNext($pendingOlt);
        Cache::put('nms:monitor:heartbeat',now()->toIso8601String(),180); $count=0;
        Olt::where('connection_mode','real')->where('monitoring_enabled',true)->orderBy('id')->chunkById(100,function($olts) use(&$count) {
            foreach($olts as $olt) {
                if(OltOperation::where('active_olt_id',$olt->id)->exists()) continue;
                $failed=OltOperation::where('olt_id',$olt->id)->where('status','failed')->whereIn('kind',['inventory','sync'])->latest('id')->first();
                if($failed?->completed_at?->gt(now()->subSeconds(config('olt.poll_retry_seconds',120)))) continue;
                $features=app(OltCapabilities::class)->describe($olt)['features'];
                $schedules=['inventory'=>['state_observed_at','state_interval',300],'traffic'=>['traffic_observed_at','traffic_interval',60],'optics'=>['optical_observed_at','optical_interval',300],'names'=>['names_observed_at','names_interval',900],'system'=>['system_observed_at','system_interval',300],'backup'=>['last_backup_at','backup_interval',86400]];
                $schedules=array_filter($schedules,fn($kind)=>$features[$kind]??false,ARRAY_FILTER_USE_KEY);
                if($olt->chassis_type==='C1600' && ($features['traffic']??false))$schedules['traffic']=['traffic_observed_at','yotc_traffic_interval',60];
                elseif(($features['traffic']??false)&&app(OltTrafficService::class)->coverage($olt)['overdue']>0)$schedules['traffic']=['traffic_observed_at','traffic_batch_interval',15];
                // C1600 web inventory stays slow; its separate CLI rate reads have a 60-second floor.
                foreach($schedules as $kind=>&$schedule)$schedule[2]=max($olt->chassis_type==='C1600'?($kind==='traffic'?60:300):1,(int)config('olt.'.$schedule[1],$schedule[2]));unset($schedule);
                uasort($schedules,function($a,$b)use($olt){$score=fn($x)=>$olt->{$x[0]}?max(0,now()->timestamp-$olt->{$x[0]}->timestamp)/$x[2]:PHP_INT_MAX;return $score($b)<=>$score($a);});
                foreach($schedules as $kind=>[$field,$setting,$interval]) {
                    // An unavailable optional metric must not monopolize every monitor tick.
                    $lastFailure=OltOperation::where('olt_id',$olt->id)->where('kind',$kind)->whereIn('status',['failed','partial'])->latest('completed_at')->first();
                    $retryDelay=max(config('olt.poll_retry_seconds',120),$interval);
                    if($lastFailure?->completed_at?->gt(now()->subSeconds($retryDelay)))continue;
                    if(!$olt->$field || $olt->$field->lte(now()->subSeconds($interval))) { $this->enqueue($olt,$kind); $count++; break; }
                }
            }
        }); return $count;
    }

    public function dispatchQueued(OltOperation $op, int $delay=0): void {
        // Call inside the same DB transaction that publishes queued state.
        $op->update(['heartbeat_at'=>now()]);
        $job=(new PollOlt($op->id, $op->stage))->onConnection('olt')->onQueue('olt')->beforeCommit();
        if($delay) \Illuminate\Support\Facades\Queue::connection('olt')->later($delay,$job,'','olt');
        else \Illuminate\Support\Facades\Queue::connection('olt')->push($job,'','olt');
    }

    public function recoverQueued(): void {
        OltOperation::where('status','queued')->whereNotNull('active_olt_id')
            ->where(fn($q)=>$q->where('heartbeat_at','<',now()->subMinutes(2))->orWhere(fn($q)=>$q->whereNull('heartbeat_at')->where('created_at','<',now()->subMinutes(2))))
            ->pluck('id')->each(function($id) {
                DB::transaction(function()use($id){
                    $op=OltOperation::whereKey($id)->lockForUpdate()->first();
                    if(!$op || $op->status!=='queued' || !$op->active_olt_id || $op->heartbeat_at?->gt(now()->subMinutes(2)))return;
                    // Include delayed and reserved jobs. Never deserialize queue payloads.
                    foreach(DB::table('jobs')->where('queue','olt')->pluck('payload') as $payload){
                        $data=json_decode($payload,true);
                        if(($data['displayName']??null)!==PollOlt::class)continue;
                        if(preg_match('/s:11:"operationId";i:'.(int)$op->id.';/', $data['data']['command']??''))return;
                    }
                    $this->dispatchQueued($op);
                });
            });
    }
}
