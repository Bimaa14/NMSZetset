<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IntegrationApiController as Api;
Route::prefix('v1')->middleware(['api.token:read','throttle:120,1'])->group(function() {
    Route::get('olts',[Api::class,'olts']); Route::get('onus/search',[Api::class,'search']);
    Route::get('onus/{id}/live',[Api::class,'live'])->middleware('throttle:20,1');
    Route::get('monitoring/bad-rx',[Api::class,'badRx']); Route::get('operations/{id}',[Api::class,'operation']);
    Route::post('onus/{id}/reboot',[Api::class,'reboot'])->middleware(['api.token:write','throttle:10,1']);
});
