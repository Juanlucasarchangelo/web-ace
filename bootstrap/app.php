<?php

use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\EnsureDev;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API autenticada por cookie de sessão, CSRF e Sanctum para SPA first-party.
        $middleware->statefulApi();
        $middleware->alias(['tenant.member' => EnsureTenantMembership::class, 'dev.only' => EnsureDev::class]);
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->api(append: [SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Illuminate\Http\Client\ConnectionException $exception): void {
            $request = request();
            if (! $request->is('api/v1/cloud/google/*')) return;
            $tenant = $request->attributes->get('current_tenant');
            if (! $tenant) return;
            $accountId = $request->input('account_id');
            $connection = $accountId && ctype_digit((string) $accountId)
                ? \App\Models\GoogleDriveConnection::where('tenant_id', $tenant->id)->find((int) $accountId) : null;
            try { app(\App\Services\GoogleDriveHealth::class)->failure($tenant->id, $connection, 'network_error'); }
            catch (\Throwable $logFailure) { /* Do not mask the original transport failure. */ }
        });
        $exceptions->report(function (\Throwable $exception): void {
            $request = request();
            if (! $request->is('api/v1/*') || $request->is('api/v1/cloud/google/*') || $request->is('api/v1/dev/*')) return;
            if ($exception instanceof \Illuminate\Validation\ValidationException || $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) return;
            $tenant = $request->attributes->get('current_tenant');
            if ($tenant) {
                try { app(\App\Services\GoogleDriveHealth::class)->failure($tenant->id, null, 'internal_error', 'ace_backend'); }
                catch (\Throwable $logFailure) { /* Database may be the original cause. */ }
            }
        });
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $e): bool =>
            $request->is('api/*') || $request->expectsJson());
    })
    ->create();
