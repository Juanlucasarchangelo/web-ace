<?php

use App\Http\Controllers\Api\V1\BillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/billing/coupon/quote', [BillingController::class, 'couponQuote'])->middleware('throttle:20,1');
    Route::get('/billing/plans', [BillingController::class, 'catalog'])->middleware('throttle:30,1');
    Route::post('/billing/signup', [BillingController::class, 'register'])->middleware('throttle:5,10');
    Route::post('/billing/verify-email', [BillingController::class, 'verifyEmail'])->middleware('throttle:10,10');
    Route::get('/billing/orders/{order}', [BillingController::class, 'orderStatus'])->middleware('throttle:30,1');
    Route::post('/billing/webhook', [BillingController::class, 'webhook'])->middleware('throttle:120,1');
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/billing/assistant/status', [\App\Http\Controllers\Api\V1\BillingAssistantController::class, 'status']);
        Route::post('/billing/assistant/chat', [\App\Http\Controllers\Api\V1\BillingAssistantController::class, 'chat'])->middleware('throttle:20,1');
        Route::get('/billing/current', [BillingController::class, 'current']);
        Route::post('/billing/renew', [BillingController::class, 'renew'])->middleware('throttle:5,10');
    });
});
