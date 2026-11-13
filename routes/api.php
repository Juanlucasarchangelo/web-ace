<?php

use App\Http\Controllers\Api\V1\{AuthController, DashboardController, ClientController, SiteController, SiteAccessController, WorkspaceController, TeamController, ActivityController, VaultController, GoogleDriveController};
use Illuminate\Support\Facades\Route;

// O prefixo /api é aplicado pelo Laravel em bootstrap/app.php.
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/profile', [\App\Http\Controllers\Api\V1\ProfileController::class, 'show']);
        Route::put('/auth/profile', [\App\Http\Controllers\Api\V1\ProfileController::class, 'update']);
        Route::put('/auth/profile/notifications', [\App\Http\Controllers\Api\V1\ProfileController::class, 'notificationEmails'])->middleware('throttle:10,1');
        Route::put('/auth/profile/password', [\App\Http\Controllers\Api\V1\ProfileController::class, 'password'])->middleware('throttle:5,1');
        Route::get('/auth/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileController::class, 'avatar']);
        Route::post('/auth/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileController::class, 'uploadAvatar'])->middleware('throttle:10,1');
        Route::delete('/auth/profile/avatar', [\App\Http\Controllers\Api\V1\ProfileController::class, 'deleteAvatar']);
    });

    Route::middleware(['auth:sanctum', 'tenant.member'])->group(function (): void {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/popups/active', [\App\Http\Controllers\Api\V1\AcePopupController::class, 'publishedMember']);
        Route::get('/popups/{popup}/image', [\App\Http\Controllers\Api\V1\AcePopupController::class, 'memberImage'])->whereNumber('popup');

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
        Route::patch('/team/{member}/status', [TeamController::class, 'status'])->whereNumber('member');
        Route::post('/team/{member}/logout', [TeamController::class, 'logout'])->whereNumber('member');
        Route::put('/team/{member}/password', [TeamController::class, 'password'])->whereNumber('member')->middleware('throttle:10,1');
        Route::delete('/team/{member}', [TeamController::class, 'destroy'])->whereNumber('member');
        Route::get('/activity', [ActivityController::class, 'index']);

        Route::get('/cloud/google/accounts', [GoogleDriveController::class, 'accounts'])->middleware('throttle:60,1');
        Route::delete('/cloud/google/accounts/{accountId}', [GoogleDriveController::class, 'disconnectAccount'])->whereNumber('accountId')->middleware('throttle:15,1');
        Route::get('/cloud/google/status', [GoogleDriveController::class, 'status']);
        Route::post('/cloud/google/accounts/{accountId}/check', [GoogleDriveController::class, 'checkAccount'])->whereNumber('accountId')->middleware('throttle:10,1');
        Route::get('/cloud/google/authorize', [GoogleDriveController::class, 'authorize'])->middleware('throttle:10,1');
        Route::get('/cloud/google/files', [GoogleDriveController::class, 'files'])->middleware('throttle:60,1');
        Route::get('/cloud/google/files/{fileId}/download', [GoogleDriveController::class, 'download'])->middleware('throttle:20,1');
        Route::post('/cloud/google/folders', [GoogleDriveController::class, 'folder'])->middleware('throttle:20,1');
        Route::post('/cloud/google/files', [GoogleDriveController::class, 'upload'])->middleware('throttle:20,1');
        Route::delete('/cloud/google/connection', [GoogleDriveController::class, 'disconnect']);
    });
});

// Chamados acessíveis inclusive quando a assinatura está bloqueada por vencimento.
// O controller valida empresa, cargo e isolamento de cada chamado sem contornar outras APIs.
Route::prefix('v1/support')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    $support = \App\Http\Controllers\Api\V1\SupportTicketController::class;
    Route::get('/tickets', [$support, 'index']);
    Route::post('/tickets', [$support, 'store'])->middleware('throttle:6,10');
    Route::get('/tickets/{ticket}', [$support, 'show'])->whereUuid('ticket');
    Route::post('/tickets/{ticket}/reply', [$support, 'reply'])->whereUuid('ticket')->middleware('throttle:15,10');
    Route::post('/tickets/{ticket}/close', [$support, 'close'])->whereUuid('ticket');
});

// Vitrine pública: consulta somente patrocinadores ativos e suas imagens validadas.
Route::prefix('v1/public')->middleware('throttle:120,1')->group(function (): void {
    $sponsors = \App\Http\Controllers\Api\V1\AceSponsorController::class;
    Route::get('/sponsors', [$sponsors, 'published']);
    Route::get('/sponsors/{sponsor}/logo', [$sponsors, 'logo'])->whereNumber('sponsor');
    $popups = \App\Http\Controllers\Api\V1\AcePopupController::class;
    Route::get('/popups', [$popups, 'published']);
    Route::get('/popups/{popup}/image', [$popups, 'image'])->whereNumber('popup');
    $ads = \App\Http\Controllers\Api\V1\AceAdController::class;
    Route::get('/ads', [$ads, 'published']);
    Route::get('/ads/{ad}/image', [$ads, 'image'])->whereNumber('ad');
    Route::get('/ads/{ad}/embed', [$ads, 'embed'])->whereNumber('ad');
    Route::get('/ads/{ad}/creatives/{creative}/image', [$ads, 'creativeImage'])->whereNumber('ad')->whereNumber('creative');
});

// Área DEV estritamente separada das APIs dos workspaces.
Route::prefix('v1/dev')->middleware(['auth:sanctum', 'dev.only', 'throttle:60,1'])->group(function (): void {
    $message = \App\Http\Controllers\Api\V1\DevMessagingController::class;
    Route::get('/messaging', [$message, 'index']);
    Route::post('/messaging/diagnose', [$message, 'diagnose'])->middleware('throttle:10,1');
    Route::put('/messaging', [$message, 'save'])->middleware('throttle:10,1');
    Route::post('/messaging/test', [$message, 'test'])->middleware('throttle:10,1');
    $tickets = \App\Http\Controllers\Api\V1\DevSupportTicketController::class;
    Route::get('/support/tickets', [$tickets, 'index']);
    Route::get('/support/tickets/{ticket}', [$tickets, 'show'])->whereUuid('ticket');
    Route::post('/support/tickets/{ticket}/reply', [$tickets, 'reply'])->whereUuid('ticket')->middleware('throttle:20,10');
    Route::patch('/support/tickets/{ticket}', [$tickets, 'update'])->whereUuid('ticket');
    $ads = \App\Http\Controllers\Api\V1\AceAdController::class;
    Route::get('/ads', [$ads, 'index']);
    Route::get('/ads/{ad}/image', [$ads, 'adminImage'])->whereNumber('ad');
    Route::get('/ads/{ad}/creatives/{creative}/image', [$ads, 'adminCreativeImage'])->whereNumber('ad')->whereNumber('creative');
    Route::post('/ads', [$ads, 'store']);
    Route::post('/ads/{ad}', [$ads, 'update'])->whereNumber('ad');
    Route::delete('/ads/{ad}', [$ads, 'destroy'])->whereNumber('ad');
    $popups = \App\Http\Controllers\Api\V1\AcePopupController::class;
    Route::get('/popups', [$popups, 'index']);
    Route::get('/popups/{popup}/image', [$popups, 'adminImage'])->whereNumber('popup');
    Route::post('/popups', [$popups, 'store']);
    Route::post('/popups/{popup}', [$popups, 'update'])->whereNumber('popup');
    Route::delete('/popups/{popup}', [$popups, 'destroy'])->whereNumber('popup');
    $sponsors = \App\Http\Controllers\Api\V1\AceSponsorController::class;
    Route::get('/sponsors', [$sponsors, 'index']);
    Route::get('/sponsors/{sponsor}/logo', [$sponsors, 'adminLogo'])->whereNumber('sponsor');
    Route::post('/sponsors', [$sponsors, 'store']);
    Route::post('/sponsors/{sponsor}', [$sponsors, 'update'])->whereNumber('sponsor');
    Route::delete('/sponsors/{sponsor}', [$sponsors, 'destroy'])->whereNumber('sponsor');
    $dev = \App\Http\Controllers\Api\V1\DevController::class;
    Route::get('/overview', [$dev, 'overview']);
    Route::post('/provision/tenants', [\App\Http\Controllers\Api\V1\DevProvisionController::class, 'createTenant'])->middleware('throttle:10,1');
    Route::post('/provision/users', [\App\Http\Controllers\Api\V1\DevProvisionController::class, 'createUser'])->middleware('throttle:15,1');
    Route::get('/tenants', [$dev, 'tenants']);
    Route::get('/users', [$dev, 'users']);
    Route::get('/issues', [$dev, 'issues']);
    Route::get('/audit', [$dev, 'audit']);
    Route::post('/issues/{issue}/resolve', [$dev, 'resolveIssue'])->whereNumber('issue');
    Route::get('/tenants/{tenant}/connections', [$dev, 'connections']);
    Route::post('/tenants/{tenant}/connections/{connection}/check', [$dev, 'checkConnection'])->whereNumber('connection')->middleware('throttle:10,1');
    Route::patch('/tenants/{tenant}/seats', [$dev, 'setSeats']);
    Route::patch('/tenants/{tenant}/billing', [$dev, 'setBilling']);
    Route::get('/ai/status', [\App\Http\Controllers\Api\V1\DevAiController::class, 'status']);
    Route::post('/ai/chat', [\App\Http\Controllers\Api\V1\DevAiController::class, 'chat'])->middleware('throttle:20,1');
    $affiliates = \App\Http\Controllers\Api\V1\DevAffiliateController::class;
    Route::get('/affiliates', [$affiliates, 'index']);
    Route::get('/affiliates/candidates', [$affiliates, 'candidates'])->middleware('throttle:20,1');
    Route::post('/affiliates', [$affiliates, 'storeAffiliate'])->middleware('throttle:10,1');
    Route::patch('/affiliates/{affiliate}', [$affiliates, 'updateAffiliate'])->whereNumber('affiliate')->middleware('throttle:10,1');
    Route::post('/affiliates/coupons', [$affiliates, 'storeCoupon'])->middleware('throttle:10,1');
    Route::patch('/affiliates/coupons/{coupon}', [$affiliates, 'updateCoupon'])->whereNumber('coupon')->middleware('throttle:10,1');
    Route::get('/affiliates/sales', [$affiliates, 'sales']);
    Route::get('/plans', [\App\Http\Controllers\Api\V1\DevPlanController::class, 'index']);
    Route::get('/plans/{plan}/history', [\App\Http\Controllers\Api\V1\DevPlanController::class, 'history'])->where('plan', 'free|cloud|pro');
    Route::patch('/plans/{plan}', [\App\Http\Controllers\Api\V1\DevPlanController::class, 'update'])->where('plan', 'free|cloud|pro')->middleware('throttle:10,1');
});

// ACE Google Drive Manager: rotas adicionais autenticadas (OAuth original preservado).
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant.member'])->group(function () {
    require __DIR__ . '/ace-drive-manager.php';
});

// Pagamentos: endpoints públicos limitados + consulta/renovação autenticadas.
require __DIR__ . '/ace-billing.php';

// ACE IA: rotas autenticadas e isoladas por workspace.
require __DIR__ . '/ace-ai.php';
