<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);
        $user = User::where('email', Str::lower($credentials['email']))->first();
        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }
        // Global DEV is a separate, zero-membership identity. No tenant APIs are available.
        $tenant = $user->is_dev ? null : $user->tenants()
            ->where('tenants.is_active', true)->wherePivot('is_active', true)->orderBy('tenants.created_at')->first();
        if (! $user->is_dev && ! $tenant) {
            throw ValidationException::withMessages(['email' => 'Conta sem empresa ativa.']);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit($request, $user, $tenant?->id, $user->is_dev ? 'dev.login' : 'auth.login');
        return response()->json(['message' => 'Login realizado.', 'data' => $this->identity($user, $tenant)]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 403, 'Conta inativa.');
        if ($user->is_dev) return response()->json(['data' => $this->identity($user, null)]);
        return response()->json(['data' => $this->identity($user, $this->tenantFromRequest($request, $user))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user && ! $user->is_dev ? $user->tenants()->where('tenants.is_active', true)->wherePivot('is_active', true)->first() : null;
        if ($user) $this->audit($request, $user, $tenant?->id, $user->is_dev ? 'dev.logout' : 'auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Sessão encerrada.']);
    }

    private function tenantFromRequest(Request $request, User $user): Tenant
    {
        $tenantId = $request->header('X-Tenant-Id');
        abort_if($tenantId && ! preg_match('/^[a-f0-9-]{36}$/i', $tenantId), 422, 'Identificador inválido.');
        $tenant = $user->tenants()->where('tenants.is_active', true)->wherePivot('is_active', true)
            ->orderBy('tenants.created_at')->first();
        abort_if($tenantId && $tenant && $tenantId !== $tenant->id, 403,
            'Esta conta está vinculada a outra empresa.');
        abort_unless($tenant, 403, 'Empresa não encontrada ou sem permissão.');
        return $tenant;
    }

    private function identity(User $user, ?Tenant $tenant): array
    {
        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'avatar_url' => $user->avatar_path ? '/api/v1/auth/profile/avatar' : null],
            'tenant' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name,
                'slug' => $tenant->slug, 'plan' => $tenant->plan ?? 'free',
                'billing_blocked' => \App\Http\Controllers\Api\V1\BillingController::blocked($tenant)] : null,
            'role' => $user->is_dev ? 'dev' : $tenant->pivot->role,
            'account_type' => $user->is_dev ? 'dev' : 'tenant',
        ];
    }

    private function audit(Request $request, User $user, ?string $tenantId, string $action): void
    {
        AuditEvent::create(['tenant_id' => $tenantId, 'user_id' => $user->id,
            'action' => $action, 'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''), 'created_at' => now()]);
    }
}
