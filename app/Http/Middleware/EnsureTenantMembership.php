<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ! $user->is_dev, 403, 'Conta inativa.');
        $tenantId = $request->header('X-Tenant-Id');
        abort_if($tenantId && !preg_match('/^[a-f0-9-]{36}$/i', $tenantId), 422, 'Identificador inválido.');
        // A customer login is bound to one contracted company. Preserve legacy links
        // in the database for DEV review, but never allow switching through headers.
        $tenant = $user->tenants()->where('tenants.is_active', true)
            ->orderBy('tenants.created_at')->first();
        abort_if($tenantId && $tenant && $tenantId !== $tenant->id, 403,
            'Esta conta está vinculada a outra empresa.');
        abort_unless($tenant, 403, 'Empresa não encontrada ou sem permissão.');
        if (\App\Http\Controllers\Api\V1\BillingController::blocked($tenant)) {
            abort(402, 'Assinatura vencida ou aguardando confirmação do pagamento.');
        }
        $request->attributes->set('current_tenant', $tenant);
        return $next($request);
    }
}
