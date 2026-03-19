<?php

use App\Http\Controllers\SiteController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('listar')->group(function () {
    Route::get('/clientes', [UserController::class, 'index']);
    Route::get('/sites', [SiteController::class, 'index']);
    Route::get('/site-id', [SiteController::class, 'getSitesById']);
});

Route::prefix('cadastrar')->group(function () {
    Route::post('/cliente', [UserController::class, 'create']);
    Route::post('/site', [SiteController::class, 'create']);
});

Route::prefix('editar')->group(function () {
    Route::post('/cliente', [UserController::class, 'create']);
    Route::post('/site', [SiteController::class, 'update']);
});

Route::prefix('excluir')->group(function () {
    Route::post('/cliente', [UserController::class, 'destroy']);
    Route::post('/site', [SiteController::class, 'destroy']);
});
