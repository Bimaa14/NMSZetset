<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        if (!$request->filled('username') && $request->filled('email')) {
            $request->merge(['username' => $request->input('email')]);
        }
        $input = $request->validate(['username' => 'required|string|max:254', 'password' => 'required|string']);
        $identifier = User::normalizeUsername($input['username']);
        $user = User::where('username', $identifier)
            ->when(str_contains($identifier, '@'), fn ($q) => $q->orWhereRaw('LOWER(email) = ?', [$identifier]))
            ->first();
        if (!$user || !Auth::attempt(['username' => $user->username, 'password' => $input['password']])) {
            return back()->withErrors(['username' => 'Username atau kata sandi tidak sesuai.'])->onlyInput('username');
        }
        $request->session()->regenerate(); $request->session()->forget('logged_out');
        $request->session()->put('demo_initialized', true);
        return redirect('/');
    }
    public function demo(Request $request)
    {
        abort_unless(app()->environment('local') && config('nms.demo'), 404);
        $input = $request->validate(['role' => 'required|in:isp_owner,noc,cs,teknisi']);
        Auth::login(User::where('username', $input['role'])->orWhere('email', $input['role'].'@bima.test')->firstOrFail());
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
