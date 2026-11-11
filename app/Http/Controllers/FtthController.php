<?php
namespace App\Http\Controllers;
use App\Models\{FtthAssignment,FtthNode,Olt,Onu,Tenant};
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
class FtthController extends Controller {
    private function node(Request $r,int $id): FtthNode { return FtthNode::where('tenant_id',$r->user()->tenant_id)->findOrFail($id); }
    private function authorize(Request $r,bool $write=false): void { abort_unless($r->user()->allows('maps') && (!$write || $r->user()->allows('operate')),403); }
    public function index(Request $r) {
        $this->authorize($r);
        return response()->json(['nodes'=>FtthNode::where('tenant_id',$r->user()->tenant_id)->withCount('assignments')->get(),'assignments'=>FtthAssignment::whereIn('node_id',FtthNode::where('tenant_id',$r->user()->tenant_id)->select('id'))->get()]);
    }
    public function save(Request $r,AuditRecorder $audit,?int $id=null) {
        $this->authorize($r,true); $tenant=$r->user()->tenant_id; $node=$id?$this->node($r,$id):new FtthNode(['tenant_id'=>$tenant]);
        $d=$r->validate(['olt_id'=>['required','integer',Rule::exists('olts','id')->where('tenant_id',$tenant)],'parent_id'=>'nullable|integer','type'=>'required|in:OTB,ODC,ODP,POLE','name'=>['required','string','max:120',Rule::unique('ftth_nodes')->where('tenant_id',$tenant)->where('olt_id',$r->input('olt_id'))->ignore($id)],'description'=>'nullable|string|max:2000','latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180','capacity'=>'required|integer|min:1|max:1024']);
        return DB::transaction(function() use($r,$audit,$node,$d,$id,$tenant) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $parent=!empty($d['parent_id'])?$this->node($r,$d['parent_id']):null;
            if($parent) {
                abort_unless($parent->olt_id===(int)$d['olt_id'],422,'Induk harus berada pada OLT yang sama.');
                $visited=[]; $cursor=$parent;
                while($cursor) { abort_if($cursor->id===$id || isset($visited[$cursor->id]),422,'Hubungan jaringan membentuk siklus.'); $visited[$cursor->id]=true; $cursor=$cursor->parent_id?FtthNode::where('tenant_id',$tenant)->find($cursor->parent_id):null; }
            }
            if($id) {
                abort_if($node->olt_id!==(int)$d['olt_id'] && (FtthNode::where('parent_id',$id)->exists() || $node->assignments()->exists()),422,'Lepaskan hubungan sebelum memindahkan perangkat ke OLT lain.');
                abort_if($node->assignments()->where('port_number','>',$d['capacity'])->exists(),422,'Port yang akan dipangkas masih terpakai.');
                abort_if($d['type']!=='ODP' && $node->assignments()->exists(),422,'Perangkat masih terhubung ke ONU.');
            }
            $node->fill($d)->save(); $audit->record($r,$id?'ftth.node.update':'ftth.node.create',$node->name,$node->olt_id);
            return response()->json(['message'=>'Perangkat FTTH disimpan.','node'=>$node],$id?200:201);
        });
    }
    public function destroy(Request $r,int $id,AuditRecorder $audit) {
        $this->authorize($r,true); $node=$this->node($r,$id);
        DB::transaction(function() use($r,$node,$audit) {
            Tenant::whereKey($r->user()->tenant_id)->lockForUpdate()->firstOrFail();
            abort_if(FtthNode::where('parent_id',$node->id)->exists() || $node->assignments()->exists(),422,'Lepaskan perangkat anak dan ONU sebelum menghapus.');
            $audit->record($r,'ftth.node.delete',$node->name,$node->olt_id); $node->delete();
        }); return response()->json(['message'=>'Perangkat FTTH dihapus.']);
    }
    public function assign(Request $r,int $id,AuditRecorder $audit) {
        $this->authorize($r,true); $node=$this->node($r,$id); $d=$r->validate(['onu_id'=>'required|integer','port_number'=>'required|integer|min:1|max:1024']);
        return DB::transaction(function() use($r,$node,$d,$audit) {
            $node=FtthNode::whereKey($node->id)->lockForUpdate()->firstOrFail();
            $onu=Onu::current()->where('tenant_id',$r->user()->tenant_id)->where('olt_id',$node->olt_id)->lockForUpdate()->findOrFail($d['onu_id']);
            abort_unless($node->type==='ODP' && $d['port_number']<=$node->capacity,422,'Pilih port ODP yang valid.');
            abort_if(FtthAssignment::where('node_id',$node->id)->where('port_number',$d['port_number'])->where('onu_id','!=',$onu->id)->exists(),422,'Port ODP sudah dipakai ONU lain.');
            FtthAssignment::updateOrCreate(['onu_id'=>$onu->id],['node_id'=>$node->id,'port_number'=>$d['port_number']]);
            $audit->record($r,'ftth.onu.assign',$onu->name,$node->olt_id); return response()->json(['message'=>'ONU dihubungkan ke ODP.']);
        });
    }
    public function detach(Request $r,int $id,AuditRecorder $audit) {
        $this->authorize($r,true); $onu=Onu::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);
        FtthAssignment::where('onu_id',$onu->id)->delete(); $audit->record($r,'ftth.onu.detach',$onu->name,$onu->olt_id); return response()->json(['message'=>'Hubungan ODP dilepas.']);
    }
    public function location(Request $r,int $id,AuditRecorder $audit) {
        $this->authorize($r,true); $onu=Onu::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);
        $onu->update($r->validate(['latitude'=>'required|numeric|between:-90,90','longitude'=>'required|numeric|between:-180,180']));
        $audit->record($r,'onu.location.update',$onu->name,$onu->olt_id); return response()->json(['message'=>'Lokasi ONU disimpan.']);
    }
}
