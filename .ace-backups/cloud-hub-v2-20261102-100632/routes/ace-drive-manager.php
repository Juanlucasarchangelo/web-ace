<?php

use App\Http\Controllers\Api\V1\GoogleDriveManagerController;
use Illuminate\Support\Facades\Route;

// Loaded from routes/api.php inside /api/v1 with auth:sanctum and tenant.member.
Route::get('/cloud/google/manager/items', [GoogleDriveManagerController::class, 'index'])->middleware('throttle:60,1');
Route::post('/cloud/google/manager/folders', [GoogleDriveManagerController::class, 'createFolder'])->middleware('throttle:20,1');
Route::post('/cloud/google/manager/uploads', [GoogleDriveManagerController::class, 'upload'])->middleware('throttle:20,1');
Route::patch('/cloud/google/manager/items/{fileId}/rename', [GoogleDriveManagerController::class, 'rename'])->where('fileId', '[a-zA-Z0-9_-]{5,200}')->middleware('throttle:30,1');
Route::patch('/cloud/google/manager/items/{fileId}/move', [GoogleDriveManagerController::class, 'move'])->where('fileId', '[a-zA-Z0-9_-]{5,200}')->middleware('throttle:30,1');
Route::delete('/cloud/google/manager/items/{fileId}', [GoogleDriveManagerController::class, 'trash'])->where('fileId', '[a-zA-Z0-9_-]{5,200}')->middleware('throttle:30,1');
Route::get('/cloud/google/manager/items/{fileId}/preview', [GoogleDriveManagerController::class, 'preview'])->where('fileId', '[a-zA-Z0-9_-]{5,200}')->middleware('throttle:30,1');
