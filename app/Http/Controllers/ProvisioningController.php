<?php
namespace App\Http\Controllers;
use App\Models\{Olt,Onu,ProvisionPlan,ServiceProfile};
use App\Services\{AuditRecorder,DemoOltOperations};
use App\Services\Olt\{OltConnectionException,OltOperationService,RealOltOperations,ZteProvisioningService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
class ProvisioningController extends Controller {
    private function olt(Request $r,int $id):Olt {$olt=Olt::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);Gate::authorize('sync',$olt);app(\App\Services\Olt\OltCapabilities::class)->requireFeature($olt,'provision');return $olt;}
    private function connection(Olt $o):string{return hash('sha256',json_encode(array_intersect_key($o->getRawOriginal(),array_flip(['connection_mode','ip_address','telnet_port','cli_transport','telnet_user','telnet_pass_enc','ssh_host_key_sha256']))));}
    private function signature(ServiceProfile $p):string{return hash('sha256',json_encode($p->only(['name','onu_type','mode','upstream_profile','downstream_profile','vlan_id','wan_profile','commands','verification'])));}
    public function profiles(Request $r){abort_unless($r->user()->allows('operate'),403);return response()->json(['profiles'=>ServiceProfile::where('tenant_id',$r->user()->tenant_id)->where('is_active',true)->get()]);}
    public function saveProfile(Request $r,AuditRecorder $audit,?int $id=null){
        abort_unless($r->user()->allows('manage'),403);
        $p=$id?ServiceProfile::where('tenant_id',$r->user()->tenant_id)->findOrFail($id):new ServiceProfile(['tenant_id'=>$r->user()->tenant_id]);
        $token=['nullable','string','max:80','regex:/^[A-Za-z0-9_.-]+$/'];
        $d=$r->validate(['name'=>['required','string','max:100',Rule::unique('service_profiles')->where('tenant_id',$r->user()->tenant_id)->ignore($id)],'onu_type'=>['required','string','max:40','regex:/^[A-Za-z0-9_.-]+$/'],'mode'=>'required|in:registration,bridge,pppoe,custom','upstream_profile'=>array_merge(['required_if:mode,bridge,pppoe'],$token),'downstream_profile'=>array_merge(['required_if:mode,bridge,pppoe'],$token),'vlan_id'=>'nullable|required_if:mode,bridge,pppoe|integer|min:1|max:4094','wan_profile'=>array_merge(['required_if:mode,pppoe'],$token),'commands'=>'nullable|required_if:mode,custom|array|max:80','commands.*'=>'required|string|max:512|regex:/^[\x20-\x7e]+$/']);
        $d+= $r->validate(['verification'=>'nullable|required_if:mode,custom|array|min:1|max:80','verification.*.scope'=>'required|in:interface,management','verification.*.line'=>'required|string|max:512|regex:/^[\\x20-\\x7e]+$/','verification.*.absent'=>'sometimes|boolean']);
        foreach(array_merge($d['commands']??[],array_column($d['verification']??[],'line')) as $command){
            preg_match_all('/\{\{([^}]+)\}\}/',$command,$vars);
            abort_if(array_diff($vars[1],['interface','port','onu_index','sn','name','pppoe_user','pppoe_password']),422,'Placeholder template tidak dikenal.');
            abort_if(preg_match('/\b(?:password|passwd|secret|community)\s+(?!\{\{pppoe_password\}\})\S+/i',$command),422,'Gunakan placeholder password, jangan simpan rahasia di template.');
        }
        $p->fill($d)->save();$audit->record($r,'service_profile.save',$p->name);return response()->json(['message'=>'Profil layanan disimpan.','profile'=>$p],$id?200:201);
    }
    public function deleteProfile(Request $r,int $id,AuditRecorder $audit){abort_unless($r->user()->allows('manage'),403);$p=ServiceProfile::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);$p->update(['is_active'=>false]);$audit->record($r,'service_profile.archive',$p->name);return response()->json(['message'=>'Profil layanan diarsipkan.']);}
    public function discover(Request $r,int $id,ZteProvisioningService $service,RealOltOperations $real){
        $olt=$this->olt($r,$id);$rows=$olt->connection_mode==='real'?$real->locked($olt,fn()=>$service->discover($olt)):[];
        return response()->json(['source'=>$olt->connection_mode,'onus'=>$rows,'ports'=>$olt->ports()->current()->get(),'registered'=>$olt->onus()->current()->get(['olt_port_id','onu_index','sn'])]);
    }
    public function preview(Request $r,int $id,ZteProvisioningService $service,RealOltOperations $real){
        $olt=$this->olt($r,$id);
        $d=$r->validate(['profile_id'=>'required|integer','frame'=>'required|integer|min:1|max:255','slot'=>'required|integer|min:1|max:255','port'=>'required|integer|min:1|max:32','onu_index'=>'required|integer|min:1|max:256','sn'=>'required|string|regex:/^[A-Za-z]{4}[A-Fa-f0-9]{8}$/','name'=>'required|string|max:80|regex:/^[A-Za-z0-9_.()-]+$/']);
        foreach(['frame','slot','port','onu_index'] as $key)$d[$key]=(int)$d[$key];$d['sn']=strtoupper($d['sn']);
        $profile=ServiceProfile::where('tenant_id',$olt->tenant_id)->where('is_active',true)->findOrFail($d['profile_id']);unset($d['profile_id']);$d['_connection']=$this->connection($olt);
        if($olt->connection_mode==='real')$real->locked($olt,fn()=>$service->check($olt,$d));else app(DemoOltOperations::class)->requireAvailable();
        $nonce=bin2hex(random_bytes(32));$plan=ProvisionPlan::create(['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'created_by'=>$r->user()->id,'service_profile_id'=>$profile->id,'confirmation_hash'=>hash('sha256',$nonce),'profile_signature'=>$this->signature($profile),'parameters'=>$d,'expires_at'=>now()->addMinutes(10)]);
        return response()->json(['plan'=>$plan,'confirmation_token'=>$nonce,'commands'=>$service->commands($profile,$d),'source'=>$olt->connection_mode])->header('Cache-Control','no-store');
    }
    public function apply(Request $r,int $id,ZteProvisioningService $service,RealOltOperations $real,AuditRecorder $audit,OltOperationService $operations){
        $olt=$this->olt($r,$id);$r->validate(['plan_id'=>'required|integer','confirmation_token'=>'required|string|size:64','pppoe_user'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.@+-]+$/','pppoe_password'=>'nullable|string|max:100|regex:/^[A-Za-z0-9_.@!#$%&*+\/:=^~-]+$/']);
        ProvisionPlan::where('tenant_id',$olt->tenant_id)->where('olt_id',$olt->id)->where('created_by',$r->user()->id)->findOrFail($r->input('plan_id'));
        return $real->locked($olt,function()use($r,$olt,$service,$audit,$operations){
        $plan=DB::transaction(function()use($r,$olt){
            $p=ProvisionPlan::where('tenant_id',$olt->tenant_id)->where('olt_id',$olt->id)->where('created_by',$r->user()->id)->lockForUpdate()->findOrFail($r->input('plan_id'));
            abort_unless($p->status==='planned'&&!$p->expires_at->isPast()&&hash_equals($p->confirmation_hash,hash('sha256',$r->input('confirmation_token'))),409,'Rencana sudah dipakai atau kedaluwarsa. Buat pratinjau baru.');
            abort_unless(($p->parameters['_connection']??null)===$this->connection($olt),409,'Koneksi OLT berubah. Buat pratinjau ulang.');
            $profile=ServiceProfile::where('tenant_id',$olt->tenant_id)->where('is_active',true)->findOrFail($p->service_profile_id);
            abort_unless(hash_equals($p->profile_signature,$this->signature($profile)),409,'Profil berubah. Buat pratinjau ulang.');
            if($profile->mode==='pppoe'||str_contains(json_encode($profile->commands),'{{pppoe_password}}')||str_contains(json_encode($profile->commands),'{{pppoe_user}}'))$r->validate(['pppoe_user'=>'required','pppoe_password'=>'required']);
            $p->update(['status'=>'running']);return $p;
        });
        $profile=ServiceProfile::findOrFail($plan->service_profile_id);
        abort_unless(hash_equals($plan->profile_signature,$this->signature($profile)),409,'Profil berubah. Buat pratinjau baru.');
        try {
            $message=(function()use($r,$olt,$plan,$profile,$service){
                if($olt->connection_mode==='real')return $service->apply($olt,$profile,$plan->parameters,$r->input('pppoe_user'),$r->input('pppoe_password'));
                app(DemoOltOperations::class)->requireAvailable();$p=$plan->parameters;$port=$olt->ports()->current()->where('interface_name','gpon-olt_'.$p['frame'].'/'.$p['slot'].'/'.$p['port'])->firstOrFail();
                abort_if($port->onus()->current()->where('onu_index',$p['onu_index'])->exists(),409,'ID ONU sudah terpakai.');
                Onu::create(['tenant_id'=>$olt->tenant_id,'olt_id'=>$olt->id,'olt_port_id'=>$port->id,'onu_index'=>$p['onu_index'],'sn'=>$p['sn'],'name'=>$p['name'],'device_name'=>$p['name'],'onu_type'=>$profile->onu_type,'vlan_id'=>$profile->vlan_id,'pppoe_user'=>$r->input('pppoe_user'),'phase_state'=>'offline','observation_source'=>'simulator','is_present'=>true]);
                app(DemoOltOperations::class)->refreshPortCounts($olt);return 'ONU simulator didaftarkan. Tidak ada perintah ke perangkat fisik.';
            })();
        }catch(\Throwable $e){
            $message=$e instanceof OltConnectionException?$e->getMessage():'Pendaftaran tidak selesai. Periksa inventaris sebelum mencoba ulang.';
            $plan->update(['status'=>$e instanceof OltConnectionException&&$e->reason==='provision_uncertain'?'uncertain':'failed','result'=>$message]);$audit->record($r,'onu.provision.failed',$plan->parameters['sn'],$olt->id);
            return response()->json(['message'=>$message,'plan'=>$plan],502);
        }
        // Device outcome must not be rewritten by a later audit/queue failure.
        $plan->update(['status'=>'applied','result'=>$message]);
        $warnings=[];
        foreach (['audit'=>fn()=>$audit->record($r,'onu.provision.applied',$plan->parameters['sn'],$olt->id),
            'inventory'=>fn()=>$operations->enqueue($olt,'inventory',$r->user(),$r->ip())] as $step=>$followUp) {
            try { $followUp(); }
            catch (\Throwable $e) {
                $warnings[]=$step==='audit'?'Pendaftaran berhasil; pencatatan audit gagal.':'Pendaftaran berhasil; pembaruan inventaris belum dijadwalkan. Jalankan Sync.';
                \Illuminate\Support\Facades\Log::warning('Provisioning follow-up failed',['plan_id'=>$plan->id,'step'=>$step,'exception'=>get_class($e)]);
            }
        }
        return response()->json(['message'=>$message.($warnings?' '.implode(' ',$warnings):''),'plan'=>$plan,'warnings'=>$warnings]);
        });
    }
}
