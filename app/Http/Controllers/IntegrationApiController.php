<?php
namespace App\Http\Controllers;
use App\Models\{Olt,Onu};
use App\Services\Olt\OltOperationService;
use Illuminate\Http\Request;
class IntegrationApiController extends Controller {
    public function olts(Request $r) {
        return response()->json(['status'=>'success','data'=>Olt::where('tenant_id',$r->user()->tenant_id)->withCount(['onus'=>fn($q)=>$q->current(),'onus as online_onu'=>fn($q)=>$q->current()->where('phase_state','working')])->get()]);
    }
    private function row(Onu $onu): array {
        return ['onu_id'=>$onu->id,'name'=>$onu->name,'sn'=>$onu->sn,'description'=>$onu->description,'tags'=>$onu->tags->pluck('tag_name'),'olt_name'=>$onu->olt->name,'olt_type'=>$onu->olt->chassis_type,'interface'=>preg_replace('/^(gpon|epon)-olt_/', '$1-onu_', $onu->port->interface_name).':'.$onu->onu_index,'phase_state'=>$onu->phase_state,'rx_power_dbm'=>$onu->rx_power,'olt_rx_power_dbm'=>$onu->olt_rx_power,'pppoe_username'=>$onu->pppoe_user,'vlan'=>$onu->vlan_id,'signal_quality'=>$onu->rx_power===null?'Belum tersedia':($onu->rx_power<=config('nms.bad_rx_threshold')?'Buruk':'Normal'),'last_sync'=>$onu->olt->last_sync_at,'state_observed_at'=>$onu->state_observed_at,'optical_observed_at'=>$onu->optical_observed_at];
    }
    public function search(Request $r) {
        $d=$r->validate(['q'=>'required|string|min:1|max:120']);
        $rows=Onu::current()->where('tenant_id',$r->user()->tenant_id)->with(['tags','olt','port'])->where(fn($q)=>$q->where('name','like','%'.$d['q'].'%')->orWhere('sn','like','%'.$d['q'].'%')->orWhere('description','like','%'.$d['q'].'%')->orWhereHas('tags',fn($t)=>$t->where('tag_name','like','%'.$d['q'].'%')))->orderBy('id')->paginate(100);
        return response()->json(['status'=>'success','query'=>$d['q'],'count'=>$rows->count(),'total'=>$rows->total(),'next_page_url'=>$rows->nextPageUrl(),'data'=>$rows->getCollection()->map(fn($o)=>$this->row($o))]);
    }
    public function badRx(Request $r) {
        $d=$r->validate(['threshold'=>'nullable|numeric|min:-50|max:0']); $threshold=(float)($d['threshold']??config('nms.bad_rx_threshold'));
        $rows=Onu::current()->where('tenant_id',$r->user()->tenant_id)->where('rx_power','<=',$threshold)->with(['tags','olt','port'])->orderBy('rx_power')->paginate(100);
        return response()->json(['status'=>'success','threshold_dbm'=>$threshold,'total_bad_onu'=>$rows->total(),'data'=>$rows->getCollection()->map(fn($o)=>$this->row($o)),'next_page_url'=>$rows->nextPageUrl()]);
    }
    public function live(Request $r,int $id,OltOperationService $service) {
        $onu=Onu::current()->where('tenant_id',$r->user()->tenant_id)->with(['tags','olt','port'])->findOrFail($id);
        $op=$service->enqueue($onu->olt,'inventory',$r->user(),$r->ip());
        return response()->json(['status'=>'accepted','realtime'=>false,'source'=>'stored','message'=>'Pembacaan perangkat dijadwalkan. Poll endpoint operasi untuk hasil terbaru.','operation_id'=>$op->id,'operation_url'=>url('/api/v1/operations/'.$op->id),'data'=>$this->row($onu)],202);
    }
    public function operation(Request $r,int $id) { return \App\Models\OltOperation::where('tenant_id',$r->user()->tenant_id)->findOrFail($id); }
    public function reboot(Request $r,int $id) { return app(OnuController::class)->reboot($r,$id,app(\App\Services\DemoOltOperations::class),app(\App\Services\AuditRecorder::class)); }
}
