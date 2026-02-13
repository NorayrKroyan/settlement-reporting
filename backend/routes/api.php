<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\SettlementHistoryController;
use App\Http\Controllers\Api\InboundLoadMatchingController;

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
*/
Route::get('/health', fn () => response()->json(['ok' => true]));

/*
|--------------------------------------------------------------------------
| Lookups
|--------------------------------------------------------------------------
*/
Route::get('/lookups/clients', [LookupController::class, 'clients']);
Route::get('/lookups/carriers', [LookupController::class, 'carriers']);
Route::get('/lookups/factor-percent', [LookupController::class, 'factorPercent']);

/*
|--------------------------------------------------------------------------
| Settlements
|--------------------------------------------------------------------------
*/
Route::prefix('settlements')->group(function () {
    Route::post('/build', [SettlementController::class, 'build']);
    Route::get('/history', [SettlementHistoryController::class, 'history']);
    Route::get('/{id}', [SettlementController::class, 'show'])
        ->whereNumber('id');
    Route::get('/{id}/pdf', [SettlementController::class, 'pdf'])
        ->whereNumber('id');
    Route::get('/history/pdf', [SettlementHistoryController::class, 'historyPdf']);

});

//Inbound loads
Route::get('/inbound-loads/queue', [InboundLoadMatchingController::class, 'queue']);
Route::post('/inbound-loads/process', [InboundLoadMatchingController::class, 'process']);



