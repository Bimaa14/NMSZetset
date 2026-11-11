<?php
namespace App\Http\Controllers;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
class ProfileController extends Controller {
    public function update(Request $r,AuditRecorder $audit) {
        $d=$r->validate(['name'=>'required|string|max:100','email'=>['required','email','max:255',Rule::unique('users')->ignore($r->user()->id)],'current_password'=>'required|current_password','password'=>'nullable|string|min:12|max:255|confirmed']);
        $user=$r->user(); $user->name=$d['name']; $user->email=$d['email'];
        if(!empty($d['password'])) $user->password=$d['password'];
        $user->save(); $audit->record($r,'profile.update',$user->name); $r->session()->regenerate();
        return response()->json(['message'=>'Profil disimpan.','csrf_token'=>csrf_token()]);
    }
}
