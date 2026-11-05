<?php

use App\Http\Controllers\Api\V1\AceAiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant.member'])->group(function (): void {
    Route::get('/ai/status', [AceAiController::class, 'status'])->name('ace.ai.status');
    Route::post('/ai/chat', [AceAiController::class, 'chat'])
        ->middleware('throttle:20,1')->name('ace.ai.chat');
    Route::post('/ai/confirm', [AceAiController::class, 'confirm'])
        ->middleware('throttle:10,1')->name('ace.ai.confirm');
});
