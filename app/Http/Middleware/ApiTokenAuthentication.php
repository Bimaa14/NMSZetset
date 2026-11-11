<?php
namespace App\Http\Middleware;
use App\Models\{ApiToken,User};
use Closure;
use Illuminate\Http\Request;
class ApiTokenAuthentication {
    public function handle(Request $request,Closure $next,string $ability='read') {
        $plain=$request->bearerToken();
        $token=$plain?ApiToken::where('token_hash',hash('sha256',$plain))->first():null;
        if(!$token || ($token->expires_at && $token->expires_at->isPast())) return response()->json(['message'=>'Token tidak valid atau kedaluwarsa.'],401);
        $user=User::find($token->created_by);
        if(!$user || $user->role!=='isp_owner' || $user->tenant_id!==$token->tenant_id) return response()->json(['message'=>'Pemilik token tidak lagi memiliki akses.'],401);
        if(!in_array($ability,$token->abilities??[],true)) return response()->json(['message'=>'Token tidak memiliki izin '.$ability.'.'],403);
        $request->setUserResolver(fn()=>$user); auth()->setUser($user); $request->attributes->set('api_token',$token);
        if(!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) $token->update(['last_used_at'=>now()]);
        return $next($request);
    }
}
