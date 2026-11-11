<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoSession
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local') && config('nms.demo') && !Auth::check() && !$request->session()->get('logged_out') && !$request->session()->get('demo_initialized') && $request->is('/')) {
            $user = User::where('email', 'isp_owner@bima.test')->first();
            if ($user) { Auth::login($user); $request->session()->regenerate(); $request->session()->put('demo_initialized', true); }
        }
        return $next($request);
    }
}
