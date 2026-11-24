<?php

use App\Http\Controllers\IntegrationApiController as Api;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.token:read', 'throttle:120,1'])->group(function () {
    Route::get('olts', [Api::class, 'olts']);
    Route::get('onus/search', [Api::class, 'search']);
    Route::get('onus/{id}', [Api::class, 'show'])->whereNumber('id');
    Route::get('onus/{id}/live', [Api::class, 'live'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('monitoring/summary', [Api::class, 'summary']);
    Route::get('monitoring/power', [Api::class, 'power']);
    Route::get('monitoring/bad-rx', [Api::class, 'badRx']);
    Route::get('operations/{id}', [Api::class, 'operation'])->whereNumber('id');
    Route::post('onus/{id}/reboot', [Api::class, 'reboot'])->whereNumber('id')->middleware(['api.token:write', 'throttle:10,1']);
});
