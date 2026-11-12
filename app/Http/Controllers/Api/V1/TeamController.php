<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Team operations are strictly scoped to the authenticated contracted company. */
class TeamController extends AceController
{
    private function manager(Request $request): void
    {
        abort_unless(in_array($this->role($request), ['owner', 'admin'], true), 403,
            'Apenas Master e Administrador podem gerenciar usuários.');
    }

    private function managedMember(Request $request, int $id): User
    {
        $this->manager($request);
        $user = $this->tenant($request)->users()->where('users.id', $id)->firstOrFail();
        abort_if($user->pivot->role === 'owner', 403, 'O Master não pode ser alterado por esta tela.');
        abort_if((int) $user->id === (int) $request->user()->id, 403,
            'Para alterar sua própria conta, utilize Meu perfil.');
        return $user;
    }

    private function endSessions(User $user): void
    {
        // Sessions live in the database. No other tenant's records are removed.
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    public function index(Request $request): JsonResponse
    {
        $this->manager($request);
        $tenant = $this->tenant($request);
        $team = $tenant->users()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'users.is_active']);
        return response()->json(['data' => $team->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active && (bool) $user->pivot->is_active,
            'role' => $user->pivot->role === 'viewer' ? 'member' : $user->pivot->role,
            'is_self' => (int) $user->id === (int) $request->user()->id,
        ])->values(), 'meta' => [
            'seat_limit' => (int) $tenant->seat_limit,
            'seats_used' => $team->count(),
            'can_manage' => true,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'role' => ['required', Rule::in(['admin', 'member'])],
        ]);
        $email = Str::lower(trim($data['email']));
        $user = DB::transaction(function () use ($request, $data, $email) {
            $tenant = \App\Models\Tenant::query()->whereKey($this->tenant($request)->id)
                ->lockForUpdate()->firstOrFail();
            $used = DB::table('tenant_user')->where('tenant_id', $tenant->id)->count();
            if ($used >= (int) $tenant->seat_limit) {
                throw ValidationException::withMessages(['team' =>
                    "Limite de {$tenant->seat_limit} usuário(s) atingido. Solicite ao DEV o aumento das vagas."]);
            }
            if (User::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'E-mail já cadastrado no ACE.']);
            }
            $created = User::create([
                'name' => trim($data['name']), 'email' => $email,
                'password' => Hash::make($data['password']), 'is_active' => true,
            ]);
            $tenant->users()->attach($created->id, ['role' => $data['role'], 'is_active' => true]);
            return $created;
        });
        $this->logAction($request, 'team.user.created');
        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name,
            'email' => $user->email]], 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $user = $this->managedMember($request, $member);
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'member'])]]);
        $this->tenant($request)->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $this->endSessions($user); // Role changes take effect at next login.
        $this->logAction($request, 'team.role.updated');
        return response()->json(['message' => 'Permissão atualizada. As sessões anteriores foram encerradas.']);
    }

    public function status(Request $request, int $member): JsonResponse
    {
        $user = $this->managedMember($request, $member);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->tenant($request)->users()->updateExistingPivot($user->id, ['is_active' => $data['is_active']]);
        if (! $data['is_active']) $this->endSessions($user);
        $this->logAction($request, $data['is_active'] ? 'team.user.unblocked' : 'team.user.blocked');
        return response()->json(['message' => $data['is_active'] ? 'Usuário reativado.' : 'Usuário bloqueado e desconectado.']);
    }

    public function logout(Request $request, int $member): JsonResponse
    {
        $user = $this->managedMember($request, $member);
        $this->endSessions($user);
        $this->logAction($request, 'team.user.sessions.revoked');
        return response()->json(['message' => 'Sessões do usuário encerradas.']);
    }

    public function password(Request $request, int $member): JsonResponse
    {
        $user = $this->managedMember($request, $member);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $this->endSessions($user);
        $this->logAction($request, 'team.user.password.reset');
        return response()->json(['message' => 'Senha alterada. O usuário deverá entrar novamente.']);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $user = $this->managedMember($request, $member);
        // Detach instead of deleting user and audit history; other companies and records remain intact.
        $this->tenant($request)->users()->detach($user->id);
        $this->endSessions($user);
        $this->logAction($request, 'team.user.removed');
        return response()->json(['message' => 'Usuário removido desta empresa. Registros históricos preservados.']);
    }
}
