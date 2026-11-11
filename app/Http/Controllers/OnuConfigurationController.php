<?php
namespace App\Http\Controllers;
use App\Models\Onu;
use App\Services\{AuditRecorder,DemoOltOperations};
use App\Services\Olt\{OltConnectionException,OltOperationService,RealOltOperations,ZteOnuConfiguration};
use Illuminate\Http\Request;
class OnuConfigurationController extends Controller {
    private function perform(Request $r,int $id,string $action,array $data=[]){
        abort_unless($r->user()->allows('operate'),403);$onu=Onu::current()->where('tenant_id',$r->user()->tenant_id)->with(['olt','port'])->findOrFail($id);
        app(\App\Services\Olt\OltCapabilities::class)->requireFeature($onu->olt,'onu_configuration');
        try{
            app(RealOltOperations::class)->locked($onu->olt,function()use($onu,$action,$data,$r){
                if($onu->olt->connection_mode==='real')app(ZteOnuConfiguration::class)->apply($onu,$action,$data);else app(DemoOltOperations::class)->requireAvailable();
                $onu->update(match($action){'delete'=>['is_present'=>false],'rename'=>['name'=>$data['name'],'device_name'=>$data['name']],'admin'=>['admin_state'=>$data['enabled']?'enable':'disable']});
                app(DemoOltOperations::class)->refreshPortCounts($onu->olt);app(AuditRecorder::class)->record($r,'onu.'.$action.'.applied',$onu->sn,$onu->olt_id);
            });
        }catch(OltConnectionException $e){app(AuditRecorder::class)->record($r,'onu.'.$action.'.failed',$onu->sn,$onu->olt_id);throw $e;}
        if($onu->olt->connection_mode==='real')app(OltOperationService::class)->enqueue($onu->olt,'inventory',$r->user(),$r->ip());
        return response()->json(['message'=>$action==='delete'?'ONU dihapus dari pendaftaran perangkat; riwayat lokal dipertahankan.':'Konfigurasi ONU terverifikasi. Status koneksi diperbarui pada polling berikutnya.']);
    }
    public function rename(Request $r,int $id){return $this->perform($r,$id,'rename',$r->validate(['name'=>'required|string|max:80|regex:/^[A-Za-z0-9_.()-]+$/']));}
    public function admin(Request $r,int $id){return $this->perform($r,$id,'admin',$r->validate(['enabled'=>'required|boolean']));}
    public function destroy(Request $r,int $id){return $this->perform($r,$id,'delete');}
}
