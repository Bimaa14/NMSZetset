<?php
namespace App\Http\Controllers;
use App\Models\{Onu,OltBackup,OnuConfigurationChange};
use App\Services\Olt\{OnuToolService,OnuBackupRestore,RealOltOperations,ZteOnuDetailParser};
use Illuminate\Http\Request;
class BackupRestoreController extends Controller {
    public function preview(Request $r,int $id,OnuToolService $tool,RealOltOperations $real,OnuBackupRestore $restore){
        abort_unless($r->user()->allows('manage')&&$r->user()->allows('operate'),403);$r->validate(['onu_id'=>'required|integer']);
        $backup=OltBackup::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);
        $onu=Onu::current()->where('tenant_id',$backup->tenant_id)->where('olt_id',$backup->olt_id)->with(['olt','port'])->findOrFail($r->input('onu_id'));
        $plan=$real->readOnly($onu->olt,fn()=>$tool->session($onu,fn($client,$interface)=>$restore->prepare($onu,$backup,$client,$interface)));
        $token=bin2hex(random_bytes(32));$change=OnuConfigurationChange::create(['tenant_id'=>$onu->tenant_id,'olt_id'=>$onu->olt_id,'onu_id'=>$onu->id,'created_by'=>$r->user()->id,'kind'=>'restore','action'=>'update','payload'=>$plan,'confirmation_hash'=>hash('sha256',$token),'expires_at'=>now()->addMinutes(5)]);
        return response()->json(['onu_id'=>$onu->id,'change_id'=>$change->id,'confirmation_token'=>$token,'commands'=>array_map(fn($line)=>app(ZteOnuDetailParser::class)->sanitize($line),$plan['commands']),'message'=>'Memulihkan entri layanan dari backup. Entri tambahan di perangkat, nama pelanggan, registrasi ONU dan konfigurasi manajemen OLT tidak dihapus.'])->header('Cache-Control','no-store');
    }
}
