<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $input = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (!Auth::attempt($input)) return back()->withErrors(['email' => 'Email atau kata sandi tidak sesuai.'])->onlyInput('email');
        $request->session()->regenerate(); $request->session()->forget('logged_out');
        $request->session()->put('demo_initialized', true);
        return redirect('/');
    }
    public function demo(Request $request)
    {
        abort_unless(app()->environment('local') && config('nms.demo'), 404);
        $input = $request->validate(['role' => 'required|in:isp_owner,noc,cs,teknisi']);
        Auth::login(User::where('email', $input['role'].'@bima.test')->firstOrFail());
        $request->session()->regenerate(); $request->session()->forget('logged_out');
        $request->session()->put('demo_initialized', true);
        return redirect('/');
    }
    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        $request->session()->put('logged_out', true);
        return redirect('/login');
    }
}
