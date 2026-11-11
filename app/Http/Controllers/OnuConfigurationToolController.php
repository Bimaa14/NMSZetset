<?php
namespace App\Http\Controllers;

use App\Http\Requests\OnuToolRequest;
use App\Models\{Onu,OnuConfigurationChange,OnuDetailSnapshot};
use App\Services\AuditRecorder;
use App\Services\Olt\{OnuToolService,OltConnectionException,RealOltOperations,ZteOnuDetailParser};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnuConfigurationToolController extends Controller
{
    private function onu(Request $r,int $id):Onu{
        abort_unless($r->user()->allows('read'),403);
        $onu=Onu::current()->where('tenant_id',$r->user()->tenant_id)->with(['olt','port'])->findOrFail($id);
        app(\App\Services\Olt\OltCapabilities::class)->requireFeature($onu->olt,'onu_configuration');
        return $onu;
    }
    private function response(array $data,int $status=200){return response()->json($data,$status)->header('Cache-Control','no-store');}
    public function show(Request $r,int $id){
        $onu=$this->onu($r,$id);$snapshot=OnuDetailSnapshot::where(['onu_id'=>$onu->id,'section'=>'configuration_tool','source'=>$onu->olt->connection_mode])->first();
        return $this->response(['snapshot'=>$snapshot?->payload,'onu'=>['id'=>$onu->id,'sn'=>$onu->sn,'name'=>$onu->name,'olt_name'=>$onu->olt->name,'interface'=>$onu->port->interface_name.':'.$onu->onu_index],'source'=>$onu->olt->connection_mode]);
    }
    public function refresh(Request $r,int $id,OnuToolService $service,RealOltOperations $real){
        $onu=$this->onu($r,$id);
        try{$snapshot=$real->readOnly($onu->olt,fn()=>$service->session($onu,function($client,$i)use($service,$onu){$read=$service->read($client,$i);return $service->publish($onu,$read['state'],$service->profiles($onu,$client));}));}
        catch(OltConnectionException $e){if($e->reason!=='read_busy')throw $e;return $this->response(['skipped'=>true,'reason'=>'read_busy'],202);}
        return $this->response(['snapshot'=>$snapshot]);
    }
    public function preview(OnuToolRequest $r,int $id,OnuToolService $service,RealOltOperations $real){
        $onu=$this->onu($r,$id);$data=$r->validated();
        $payload=$real->readOnly($onu->olt,fn()=>$service->session($onu,function($client,$i)use($service,$onu,$data){
            $read=$service->read($client,$i);$profiles=$service->profiles($onu,$client);$plan=$service->prepare($data,$read['state'],$profiles,$i);
            return $plan+['fingerprint'=>$read['fingerprint'],'backup'=>$read['backup'],'connection'=>$service->connection($onu)];
        }));
        $token=bin2hex(random_bytes(32));
        $change=OnuConfigurationChange::create(['tenant_id'=>$onu->tenant_id,'olt_id'=>$onu->olt_id,'onu_id'=>$onu->id,'created_by'=>$r->user()->id,'kind'=>$data['kind'],'action'=>$data['action'],'payload'=>$payload,'confirmation_hash'=>hash('sha256',$token),'expires_at'=>now()->addMinutes(5)]);
        return $this->response(['change_id'=>$change->id,'confirmation_token'=>$token,'expires_at'=>$change->expires_at->toIso8601String(),'commands'=>array_map(fn($command)=>app(ZteOnuDetailParser::class)->sanitize($command),$payload['commands'])]);
    }
    public function apply(Request $r,int $id,OnuToolService $service,RealOltOperations $real,AuditRecorder $audit){
        abort_unless($r->user()->allows('operate'),403);$onu=$this->onu($r,$id);
        $data=$r->validate(['change_id'=>'required|integer','confirmation_token'=>'required|string|size:64']);
        // Validate ownership before acquiring any device lock or attempting IO.
        OnuConfigurationChange::where('tenant_id',$onu->tenant_id)->where('onu_id',$onu->id)->where('created_by',$r->user()->id)->findOrFail($data['change_id']);
        return $real->control($onu,function()use($r,$data,$onu,$service,$audit){
            $change=DB::transaction(function()use($r,$data,$onu,$service){
                $change=OnuConfigurationChange::where('tenant_id',$onu->tenant_id)->where('onu_id',$onu->id)->where('created_by',$r->user()->id)->lockForUpdate()->findOrFail($data['change_id']);
                if($change->kind==='restore')abort_unless($r->user()->allows('manage'),403);
                abort_unless($change->status==='planned'&&!$change->expires_at->isPast()&&hash_equals($change->confirmation_hash,hash('sha256',$data['confirmation_token'])),409,'Pratinjau sudah dipakai atau kedaluwarsa. Buat pratinjau baru.');
                abort_unless(hash_equals($change->payload['connection'],$service->connection($onu)),409,'Koneksi atau posisi ONU berubah. Buat pratinjau baru.');
                $change->update(['status'=>'applying']);return $change;
            });
            $writes=false;
            try{
                $snapshot=$service->session($onu,function($client,$i)use($service,$onu,$change,&$writes){
                    $plan=$change->payload;$before=$service->read($client,$i);
                    abort_unless(hash_equals($plan['fingerprint'],$before['fingerprint']),409,'Konfigurasi OLT berubah sejak pratinjau. Muat ulang dan buat pratinjau baru.');
                    $client->addSecrets(array_filter(array_merge([$plan['input']['password']??null],$plan['secrets']??[])));
                    foreach($plan['commands']as$index=>$command){
                        if($index>=2&&$command!=='end')$writes=true;
                        // Do not automatically answer unexpected destructive CLI prompts.
                        $client->command($command,false,5,15);
                    }
                    $after=$service->read($client,$i);
                    if(!$service->verified($plan,$after['state']))throw new OltConnectionException('Perubahan belum cocok dengan konfigurasi yang dibaca ulang. Periksa hasil sebelum mengulang.','configuration_uncertain');
                    OnuDetailSnapshot::where('onu_id',$onu->id)->whereIn('section',['wan','ethernet','wifi','config'])->delete();
                    return $service->publish($onu,$after['state'],$service->profiles($onu,$client));
                });
                $change->update(['status'=>'applied','result'=>'Perubahan terverifikasi dari OLT.']);$audit->record($r,'onu.tool.'.$change->kind.'.applied',$onu->sn,$onu->olt_id);
                return $this->response(['message'=>'Perubahan terverifikasi dari OLT.','snapshot'=>$snapshot,'change_id'=>$change->id]);
            }catch(\Throwable $e){
                $change->update(['status'=>$writes?'uncertain':'failed','result'=>$writes?'Perubahan mungkin diterapkan sebagian. Periksa konfigurasi sebelum mengulang.':'Perubahan tidak dijalankan.']);
                $audit->record($r,'onu.tool.'.$change->kind.'.'.($writes?'uncertain':'failed'),$onu->sn,$onu->olt_id);
                if(!$writes)throw $e;
                return $this->response(['message'=>'Perubahan mungkin diterapkan sebagian dan belum terverifikasi. Muat ulang konfigurasi sebelum mencoba lagi.','reason'=>'configuration_uncertain','change_id'=>$change->id],502);
            }
        });
    }
}
