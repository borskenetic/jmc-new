<?php

use App\Http\Controllers\Api\GateSyncController;
use App\Http\Middleware\AuthenticateGateDevice;
use Illuminate\Support\Facades\Route;

Route::middleware([AuthenticateGateDevice::class])->prefix('gate')->group(function () {
    Route::get('/health', [GateSyncController::class, 'health']);
    Route::get('/roster', [GateSyncController::class, 'roster']);
    Route::get('/gates', [GateSyncController::class, 'availableGates']);
    Route::post('/gates/claim', [GateSyncController::class, 'claimGate']);
    Route::post('/gates/ping', [GateSyncController::class, 'pingGate']);
    Route::post('/attendance', [GateSyncController::class, 'pushAttendance']);
});
