<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ReleasePollingSession
{
    public function handle(Request $request, Closure $next)
    {
        // Authentication and CSRF have run; polling never mutates session state.
        // Laravel's file/database handlers do not hold a native PHP session lock.
        if($request->hasSession())$request->session()->save();
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        return $next($request);
    }
}
