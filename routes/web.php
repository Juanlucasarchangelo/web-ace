<?php

use Illuminate\Support\Facades\Route;

Route::get('/cloud/google/callback', [\App\Http\Controllers\Api\V1\GoogleDriveController::class, 'callback'])
    ->middleware(['auth'])
    ->name('cloud.google.callback');

// Somente a aplicação Vue e o endpoint de saúde são servidos como web.
// Todos os endpoints JSON estão exclusivamente em routes/api.php.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api(?:/|$)|sanctum(?:/|$)|up$).*$')
    ->name('spa');
