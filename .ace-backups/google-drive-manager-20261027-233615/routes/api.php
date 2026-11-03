<?php

use App\Http\Controllers\Api\V1\{AuthController, DashboardController, ClientController, SiteController, SiteAccessController, WorkspaceController, TeamController, ActivityController, VaultController, GoogleDriveController};
use Illuminate\Support\Facades\Route;

// O prefixo /api é aplicado pelo Laravel em bootstrap/app.php.
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'tenant.member'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/clients', [ClientController::class, 'index']);
        Route::post('/clients', [ClientController::class, 'store']);
        Route::get('/clients/{client}', [ClientController::class, 'show'])->whereNumber('client');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->whereNumber('client');
        Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client');

        Route::get('/sites', [SiteController::class, 'index']);
        Route::post('/sites', [SiteController::class, 'store']);
        Route::get('/sites/{site}', [SiteController::class, 'show'])->whereNumber('site');
        Route::put('/sites/{site}', [SiteController::class, 'update'])->whereNumber('site');
        Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->whereNumber('site');
        Route::post('/sites/{site}/registry/reveal', [SiteController::class, 'revealRegistry'])->whereNumber('site')->middleware('throttle:15,1');
        Route::post('/sites/{site}/accesses', [SiteAccessController::class, 'store'])->whereNumber('site');
        Route::put('/sites/{site}/accesses/{access}', [SiteAccessController::class, 'update'])->whereNumber('site')->whereNumber('access');
        Route::post('/sites/{site}/accesses/{access}/reveal', [SiteAccessController::class, 'reveal'])->whereNumber('site')->whereNumber('access')->middleware('throttle:15,1');
        Route::delete('/sites/{site}/accesses/{access}', [SiteAccessController::class, 'destroy'])->whereNumber('site')->whereNumber('access');

        Route::get('/vault', [VaultController::class, 'index']);
        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'create']);
        Route::put('/workspaces/current', [WorkspaceController::class, 'update']);
        Route::get('/team', [TeamController::class, 'index']);
        Route::post('/team', [TeamController::class, 'store']);
        Route::put('/team/{member}', [TeamController::class, 'update'])->whereNumber('member');
        Route::delete('/team/{member}', [TeamController::class, 'destroy'])->whereNumber('member');
        Route::get('/activity', [ActivityController::class, 'index']);

        Route::get('/cloud/google/status', [GoogleDriveController::class, 'status']);
        Route::get('/cloud/google/authorize', [GoogleDriveController::class, 'authorize'])->middleware('throttle:10,1');
        Route::get('/cloud/google/files', [GoogleDriveController::class, 'files'])->middleware('throttle:60,1');
        Route::get('/cloud/google/files/{fileId}/download', [GoogleDriveController::class, 'download'])->middleware('throttle:20,1');
        Route::post('/cloud/google/folders', [GoogleDriveController::class, 'folder'])->middleware('throttle:20,1');
        Route::post('/cloud/google/files', [GoogleDriveController::class, 'upload'])->middleware('throttle:20,1');
        Route::delete('/cloud/google/connection', [GoogleDriveController::class, 'disconnect']);
    });
});
