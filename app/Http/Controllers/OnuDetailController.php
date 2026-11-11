<?php
namespace App\Http\Controllers;
use App\Models\{Onu,OnuDetailSnapshot,FtthAssignment,FtthNode};
use App\Services\Olt\{ZteOnuDetailService,ZteOnuDetailParser,RealOltOperations};
use App\Services\{AuditRecorder,DemoOltOperations};
use Illuminate\Http\Request;
class OnuDetailController extends Controller {
    private function onu(Request $r,int $id):Onu{abort_unless($r->user()->allows('read'),403);return Onu::current()->where('tenant_id',$r->user()->tenant_id)->with(['olt','port','tags'])->findOrFail($id);}
    private function publicData(array $value):array {foreach(array_keys($value) as $key){if(in_array(strtolower((string)$key),['password','secret','passphrase','key','wpa passphrase'],true)){$value['has_'.$key]=$value[$key]!==null&&$value[$key]!=='';$value[$key]=null;}elseif(is_array($value[$key]))$value[$key]=$this->publicData($value[$key]);}return $value;}
    public function show(Request $r,int $id){
        $onu=$this->onu($r,$id);$sections=[];foreach(OnuDetailSnapshot::where('onu_id',$id)->where('source',$onu->olt->connection_mode)->get()as$s)if($s->section!=='config'||$r->user()->allows('cli'))$sections[$s->section]=$this->publicData($s->payload);
        $assignment=FtthAssignment::where('onu_id',$id)->first();$node=$assignment?FtthNode::where('tenant_id',$onu->tenant_id)->find($assignment->node_id):null;
        $position=Onu::where('tenant_id',$onu->tenant_id)->where('olt_id',$onu->olt_id)->where('olt_port_id',$onu->olt_port_id)->where('onu_index',$onu->onu_index)->orderByDesc('id')->limit(30)->get(['id','sn','created_at','last_seen_at','is_present','observation_source']);
        return response()->json(['sections'=>$sections,'odp'=>$node?['name'=>$node->name,'id'=>$node->id,'port'=>$assignment->port_number]:null,'position_history'=>$position,'description'=>app(ZteOnuDetailParser::class)->cleanDescription($onu->description),'board'=>collect($onu->olt->boards??[])->first(fn($b)=>(int)($b['slot']??$b['slot_no']??0)===$onu->port->slot_no),'source'=>$onu->olt->connection_mode])->header('Cache-Control','no-store');
    }
    public function refresh(Request $r,int $id,ZteOnuDetailService $service){$onu=$this->onu($r,$id);$d=$r->validate(['section'=>'required|in:info,wan,ethernet,iphost,wifi,config']);if($d['section']==='config')abort_unless($r->user()->allows('cli'),403);try{$payload=$this->publicData($service->refresh($onu,$d['section']));}catch(\App\Services\Olt\OltConnectionException $e){if($e->reason!=='read_busy')throw $e;return response()->json(['skipped'=>true,'reason'=>'read_busy'],202)->header('Cache-Control','no-store');}return response()->json($payload)->header('Cache-Control','no-store');}
    public function secret(Request $r,int $id,AuditRecorder $audit){$onu=$this->onu($r,$id);abort_unless($r->user()->allows('operate'),403);$d=$r->validate(['section'=>'required|in:wan,wifi','item_id'=>'required|integer|min:1|max:64']);$snapshot=OnuDetailSnapshot::where(['onu_id'=>$id,'section'=>$d['section'],'source'=>$onu->olt->connection_mode])->first();$item=collect($snapshot?->payload['data']['items']??[])->firstWhere('id',$d['item_id']);$audit->record($r,'onu.secret.view',$onu->sn,$onu->olt_id);return response()->json(['password'=>$item['password']??null])->header('Cache-Control','no-store');}
    public function configure(Request $r,int $id,ZteOnuDetailService $service,AuditRecorder $audit){
        $onu=$this->onu($r,$id);abort_unless($r->user()->allows('operate'),403);
        app(\App\Services\Olt\OltCapabilities::class)->requireFeature($onu->olt,'onu_configuration');
        $d=$r->validate(['kind'=>'required|in:wan,ethernet,wifi,factory,description','id'=>'nullable|integer|min:1|max:64','confirmed'=>'accepted','delete'=>'nullable|boolean','enabled'=>'required_if:kind,ethernet|boolean','name'=>'required_if:kind,wifi|string|max:32|regex:/^[A-Za-z0-9_.()-]+$/','description'=>'required_if:kind,description|string|max:200|regex:/^[A-Za-z0-9_ .(),:\/-]+$/','username'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_@.()+-]+$/','password'=>'nullable|string|min:1|max:64|regex:/^[A-Za-z0-9_@.()+!#%=-]+$/','vlan_profile'=>'nullable|string|max:80|regex:/^[A-Za-z0-9_.()-]+$/','host'=>'nullable|integer|min:1|max:64']);
        if($d['kind']==='wan'&&empty($d['delete']))$r->validate(['username'=>'required','password'=>'required','vlan_profile'=>'required']);
        if($d['kind']==='wifi'&&!empty($d['password']))$r->validate(['password'=>'string|min:8|max:63']);
        try{app(RealOltOperations::class)->control($onu,function()use($onu,$d,$service){if($onu->olt->connection_mode==='real')$service->configure($onu,$d['kind'],$d);else{app(DemoOltOperations::class)->requireAvailable();abort(422,'Konfigurasi layanan ini memerlukan ONU nyata.');}});$audit->record($r,'onu.detail.'.$d['kind'].'.applied',$onu->sn,$onu->olt_id);}catch(\Throwable $e){$audit->record($r,'onu.detail.'.$d['kind'].'.failed',$onu->sn,$onu->olt_id);throw $e;}
        return response()->json(['message'=>$d['kind']==='factory'?'OLT menerima reset pabrik. Periksa kembali status dan layanan ONU.':'Konfigurasi terverifikasi dari OLT.']);
    }
}
