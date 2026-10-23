<?php

use Illuminate\Support\Facades\Route;

// Somente a aplicação Vue e o endpoint de saúde são servidos como web.
// Todos os endpoints JSON estão exclusivamente em routes/api.php.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api(?:/|$)|sanctum(?:/|$)|up$).*$')
    ->name('spa');
