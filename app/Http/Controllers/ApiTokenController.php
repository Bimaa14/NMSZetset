<?php
namespace App\Http\Controllers;
use App\Models\ApiToken;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
class ApiTokenController extends Controller {
    public function index(Request $r) {
        Gate::authorize('api');
        return response()->json(['base_url'=>url('/api/v1'),'tokens'=>ApiToken::where('tenant_id',$r->user()->tenant_id)->latest('id')->get(),'documentation_url'=>url('/api/docs')])->header('Cache-Control','no-store');
    }
    public function store(Request $r,AuditRecorder $audit) {
        Gate::authorize('api'); $d=$r->validate(['name'=>'required|string|max:100','days'=>'required|integer|min:1|max:365','scope'=>'required|in:read,write']);
        $plain='nms_'.bin2hex(random_bytes(32));
        $token=ApiToken::create(['tenant_id'=>$r->user()->tenant_id,'created_by'=>$r->user()->id,'name'=>$d['name'],'token_hash'=>hash('sha256',$plain),'abilities'=>$d['scope']==='write'?['read','write']:['read'],'expires_at'=>now()->addDays($d['days'])]);
        $audit->record($r,'api.token.create',$token->name);
        return response()->json(['message'=>'Token dibuat. Salin sekarang; token tidak ditampilkan lagi.','token'=>$plain,'record'=>$token],201)->header('Cache-Control','no-store');
    }
    public function destroy(Request $r,int $id,AuditRecorder $audit) {
        Gate::authorize('api'); $token=ApiToken::where('tenant_id',$r->user()->tenant_id)->findOrFail($id);
        $audit->record($r,'api.token.revoke',$token->name); $token->delete(); return response()->json(['message'=>'Token dicabut.']);
    }
}
