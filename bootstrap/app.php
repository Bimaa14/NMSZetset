<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['api.token'=>\App\Http\Middleware\ApiTokenAuthentication::class]);
        $middleware->web(append: [\App\Http\Middleware\DemoSession::class]);
        $middleware->prependToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, \App\Http\Middleware\DemoSession::class);
        $middleware->trimStrings(except: ['telnet_password', 'snmp_community', 'pppoe_password', 'confirmation_token', 'traffic_telnet_password', 'traffic_enable_password']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['telnet_password', 'snmp_community', 'pppoe_password', 'confirmation_token', 'traffic_telnet_password', 'traffic_enable_password']);
        $exceptions->render(function (\App\Services\Olt\OltConnectionException $e, \Illuminate\Http\Request $request) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason, 'source' => 'real'], $e->reason === 'timeout' ? 504 : 502);
        });
        $exceptions->dontReport([\App\Services\Olt\OltConnectionException::class]);
    })->create();
