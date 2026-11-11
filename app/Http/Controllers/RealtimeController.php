<?php
namespace App\Http\Controllers;
use App\Models\{Olt,OltOperation,Onu};
use App\Services\Olt\OltOperationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
class RealtimeController extends Controller {
    private function olt(Request $r,int $id): Olt {
        $olt=Olt::where('tenant_id',$r->user()->tenant_id)->findOrFail($id); Gate::authorize('view',$olt); return $olt;
    }
    public function start(Request $r,int $id,OltOperationService $service) {
        $olt=$this->olt($r,$id); $data=$r->validate(['kind'=>'sometimes|in:sync,inventory,names,optics,system,traffic,backup']); $kind=$data['kind']??'sync';
        Gate::authorize($kind==='system'?'view':'sync',$olt);
        abort_if(in_array($kind,['backup','traffic'],true)&&$olt->connection_mode!=='real',422,'Operasi ini memerlukan perangkat real.');
        $op=$service->enqueue($olt,$kind,$r->user(),$r->ip());
        return response()->json(['message'=>'Operasi masuk antrean. Progres diperbarui otomatis.','operation'=>$op],202);
    }
    public function status(Request $r,int $id) {
        $olt=$this->olt($r,$id);
        return response()->json(['operations'=>OltOperation::where('olt_id',$olt->id)->where('tenant_id',$olt->tenant_id)->latest('id')->limit(20)->get()])->header('Cache-Control','no-store');
    }
    public function cancel(Request $r,int $id,int $operationId,OltOperationService $service) {
        $olt=$this->olt($r,$id);Gate::authorize('sync',$olt);
        return DB::transaction(function()use($r,$olt,$operationId,$service){
            Olt::whereKey($olt->id)->lockForUpdate()->firstOrFail();
            $op=OltOperation::where('tenant_id',$olt->tenant_id)->where('olt_id',$olt->id)->findOrFail($operationId);
            $changed=OltOperation::whereKey($op->id)->where('status','queued')->update(['status'=>'cancelled','active_olt_id'=>null,'completed_at'=>now(),'current_step'=>'Antrean dibatalkan']);
            abort_unless($changed,409,'Operasi sudah berjalan; tunggu pembacaan selesai.');
            $service->activateNext($olt);app(\App\Services\AuditRecorder::class)->record($r,'olt.operation.cancel',$olt->name,$olt->id);
            return response()->json(['message'=>'Antrean dibatalkan.']);
        });
    }
    public function monitoring(Request $r,int $id) {
        $olt=$this->olt($r,$id); Gate::authorize('update',$olt);
        $olt->update($r->validate(['monitoring_enabled'=>'required|boolean']));
        app(\App\Services\AuditRecorder::class)->record($r,'olt.monitoring.update',$olt->name,$olt->id);
        return response()->json(['message'=>$olt->monitoring_enabled?'Pemantauan otomatis aktif.':'Pemantauan otomatis dijeda.']);
    }
    public function names(Request $r,int $id) {
        $olt=$this->olt($r,$id);
        return response()->json(['source'=>'stored','observed_at'=>$olt->names_observed_at,'names'=>$olt->onus()->current()->with('port')->get()->map(fn($o)=>['id'=>$o->id,'slot'=>$o->port->slot_no,'pon'=>$o->port->port_no,'onu'=>$o->onu_index,'name'=>$o->name,'sn'=>$o->sn])]);
    }
    public function onus(Request $r) {
        abort_unless($r->user()->allows('read'),403);
        return response()->json(app(\App\Services\OnuDirectory::class)->listing($r));
    }
    public function history(Request $r,int $id) {
        abort_unless($r->user()->allows('read'),403);
        $onu=Onu::current()->where('tenant_id',$r->user()->tenant_id)->findOrFail($id);
        return response()->json(['onu_id'=>$onu->id,'source'=>$onu->observation_source,
            'traffic'=>DB::table('onu_traffic_logs')->where('onu_id',$onu->id)->orderByDesc('id')->limit(100)->get(),
            'status'=>DB::table('onu_status_logs')->where('onu_id',$onu->id)->where('source',$onu->observation_source)->orderByDesc('id')->limit(100)->get(),
            'optical'=>$onu->opticalLogs()->where('observation_source',$onu->observation_source)->orderByDesc('id')->limit(100)->get(['rx_power','olt_rx_power','calculated_at'])]);
    }
    public function capabilities(Request $r,int $id) {
        $olt=$this->olt($r,$id);
        $cap=app(\App\Services\Olt\OltCapabilities::class)->describe($olt);
        $cap['features']+=['sync'=>$r->user()->allows('operate')&&$cap['features']['monitor'],'monitoring'=>$cap['features']['monitor'],'status_history'=>true,'optical_history'=>true];
        $cap['features']['cli']=$cap['features']['cli']&&$r->user()->allows('cli');
        $cap['features']['traffic']=$cap['features']['traffic']&&$olt->connection_mode==='real';
        foreach(['port_traffic','onu_traffic'] as $feature)$cap['features'][$feature]=$cap['features'][$feature]&&$olt->connection_mode==='real';
        foreach(['provision','onu_configuration','onu_restore','reboot','backup']as$key)$cap['features'][$key]=$cap['features'][$key]&&$r->user()->allows('operate')&&($key!=='onu_restore'||$r->user()->allows('manage'));
        return response()->json($cap+['chassis'=>$olt->chassis_type,'source'=>$olt->connection_mode]);
    }
}
