<?php
namespace App\Jobs;
use App\Models\{AuditLog,OltOperation,User};
use App\Services\DemoOltOperations;
use App\Services\Olt\{OltConnectionException,RealOltOperations};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;
class PollOlt implements ShouldQueue {
    use Queueable;
    public int $tries=1;
    public int $timeout=330;
    public bool $failOnTimeout=true;
    public function __construct(public int $operationId, public ?string $expectedStage=null) {}
    public function handle(RealOltOperations $real,DemoOltOperations $demo): void {
        @set_time_limit(300);
        Cache::put('nms:worker:heartbeat',now()->toIso8601String(),600);
        $op=OltOperation::with('olt')->find($this->operationId);
        if(!$op || $op->status!=='queued' || !$op->active_olt_id || !$op->olt || $op->olt->tenant_id!==$op->tenant_id || ($this->expectedStage!==null && $this->expectedStage!==$op->stage)) return;
        $olt=$op->olt; $actor=$op->actor_type==='User'?User::find($op->actor_id):null;
        if($op->actor_type==='User' && (!$actor || $actor->tenant_id!==$op->tenant_id || !$actor->allows($op->kind==='system'?'read':'operate'))) { $this->finish($op,'failed','Akun atau izin operasi sudah berubah.'); return; }
        if($op->actor_type==='System' && (!$olt->monitoring_enabled || $olt->connection_mode!=='real')) { $this->finish($op,'failed','Pemantauan otomatis dinonaktifkan.'); return; }
        if(!OltOperation::whereKey($op->id)->where('status','queued')->where('stage',$op->stage)->update(['status'=>'running','started_at'=>$op->started_at??now(),'heartbeat_at'=>now(),'current_step'=>match($op->stage){'system'=>'Membaca metrik sistem','backup'=>'Membaca konfigurasi OLT','traffic'=>'Membaca traffic SNMP / CLI',default=>'Menghubungkan OLT'}])) return;
        $op->refresh();
        try {
            $progress=function(int $percent,string $step) use($op) {
                $base=$op->kind==='sync'?match($op->stage){'inventory'=>0,'names'=>50,'optics'=>75}:0;
                $range=$op->kind==='sync'?($op->stage==='inventory'?50:25):100;
                $op->update(['progress'=>min(99,$base+(int)($percent*$range/100)),'current_step'=>$step,'heartbeat_at'=>now()]);
            };
            if($olt->connection_mode==='real') $warnings=$real->poll($olt,$op->stage,$progress);
            else { $demo->sync($olt); $warnings=[]; }
            $op->warnings=array_values(array_unique(array_merge($op->warnings??[],$warnings)));
            if($op->kind==='sync' && $olt->connection_mode==='real' && in_array($op->stage,['inventory','names'],true)) {
                \Illuminate\Support\Facades\DB::transaction(function()use($op){
                    $op->update(['status'=>'queued','stage'=>$op->stage==='inventory'?'names':'optics','current_step'=>'Menunggu tahap berikutnya','warnings'=>$op->warnings,'heartbeat_at'=>now()]);
                    app(\App\Services\Olt\OltOperationService::class)->dispatchQueued($op);
                }); return;
            }
            $this->finish($op,$op->warnings?'partial':'completed');
        } catch(Throwable $e) {
            if(($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode()===409) || ($e instanceof OltConnectionException && $e->reason==='read_busy')){
                \Illuminate\Support\Facades\DB::transaction(function()use($op){
                    $op->update(['status'=>'queued','heartbeat_at'=>now(),'current_step'=>'Menunggu sesi OLT tersedia']);
                    app(\App\Services\Olt\OltOperationService::class)->dispatchQueued($op,10);
                });
                return;
            }
            if(!$e instanceof OltConnectionException) \Illuminate\Support\Facades\Log::warning('OLT worker failed',['operation_id'=>$op->id,'exception'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
            $this->finish($op,'failed',$e instanceof OltConnectionException?$e->getMessage():'Worker tidak dapat menyelesaikan operasi. Snapshot terakhir dipertahankan.');
        }
    }
    private function finish(OltOperation $op,string $status,?string $error=null): void {
        \Illuminate\Support\Facades\DB::transaction(function()use($op,$status,$error){
        \App\Models\Olt::whereKey($op->olt_id)->lockForUpdate()->firstOrFail();
        $op->update(['status'=>$status,'active_olt_id'=>null,'completed_at'=>now(),'heartbeat_at'=>now(),'progress'=>$status==='failed'?$op->progress:100,'error'=>$error,'warnings'=>$op->warnings??[],'current_step'=>$error??($status==='partial'?'Selesai dengan catatan':'Selesai')]);
        AuditLog::create(['tenant_id'=>$op->tenant_id,'actor_type'=>$op->actor_type,'actor_id'=>$op->actor_id??0,'olt_id'=>$op->olt_id,'action'=>'olt.'.$op->kind.'.'.$status,'target'=>$op->olt?->name??'OLT','ip_address'=>$op->ip_address??'127.0.0.1','created_at'=>now()]);
        app(\App\Services\Olt\OltOperationService::class)->activateNext($op->olt);
        });
    }
    public function failed(?Throwable $e): void {
        $op=OltOperation::find($this->operationId);
        if($op && in_array($op->status,['queued','running'],true)) $this->finish($op,'failed','Worker terhenti. Snapshot yang sudah tersimpan dipertahankan.');
    }
}
