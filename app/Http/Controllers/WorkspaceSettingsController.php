<?php
namespace App\Http\Controllers;
use App\Models\{Olt,OltBackup};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
class WorkspaceSettingsController extends Controller {
    public function preferences(Request $r){
        $d=$r->validate(['onu_columns'=>'present|array|max:12','onu_columns.*'=>['required','distinct',Rule::in(['status','rx','last_online','serial','traffic','description','pppoe','vlan','tags'])]]);
        $r->user()->forceFill(['preferences'=>array_replace($r->user()->preferences??[],$d)])->save();return response()->json(['message'=>'Pilihan kolom disimpan.']);
    }
    public function backups(Request $r,int $id){
        $olt=Olt::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);Gate::authorize('update',$olt);
        return response()->json(['backups'=>OltBackup::where('olt_id',$olt->id)->latest('id')->limit(30)->get()]);
    }
    public function download(Request $r,int $id){
        abort_unless($r->user()->allows('manage'),403);$backup=OltBackup::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);
        app(\App\Services\AuditRecorder::class)->record($r,'olt.backup.download','Backup #'.$backup->id,$backup->olt_id);
        return response($backup->configuration)->header('Content-Type','text/plain; charset=utf-8')->header('Content-Disposition','attachment; filename="olt-'.$backup->olt_id.'-backup-'.$backup->id.'.cfg"')->header('Cache-Control','no-store');
    }
}
