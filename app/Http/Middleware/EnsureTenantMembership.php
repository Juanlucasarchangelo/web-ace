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
        abort_unless($user && $user->is_active, 403, 'Conta inativa.');
        $tenantId = $request->header('X-Tenant-Id');
        abort_if($tenantId && !preg_match('/^[a-f0-9-]{36}$/i', $tenantId), 422, 'Identificador inválido.');
        $query = $user->tenants()->where('tenants.is_active', true);
        $tenant = $tenantId ? $query->where('tenants.id', $tenantId)->first()
            : $query->orderBy('tenants.created_at')->first();
        abort_unless($tenant, 403, 'Empresa não encontrada ou sem permissão.');
        $request->attributes->set('current_tenant', $tenant);
        return $next($request);
    }
}
