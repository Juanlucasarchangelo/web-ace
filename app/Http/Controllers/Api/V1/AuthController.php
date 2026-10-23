<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
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
        if (!$user || !$user->is_active || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }
        $tenant = $user->tenants()->where('tenants.is_active', true)->orderBy('tenants.created_at')->first();
        if (!$tenant) {
            throw ValidationException::withMessages(['email' => 'Conta sem empresa ativa.']);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate(); // Mitiga session fixation.
        $this->audit($request, $user, $tenant->id, 'auth.login');
        return response()->json(['message' => 'Login realizado.', 'data' => $this->identity($user, $tenant)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->identity($request->user(), $request->attributes->get('current_tenant'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        $this->audit($request, $request->user(), $tenant->id, 'auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Sessão encerrada.']);
    }

    private function identity(User $user, $tenant): array
    {
        return ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'role' => $tenant->pivot->role];
    }

    private function audit(Request $request, User $user, string $tenantId, string $action): void
    {
        AuditEvent::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'action' => $action,
            'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 512, ''), 'created_at' => now()]);
    }
}
