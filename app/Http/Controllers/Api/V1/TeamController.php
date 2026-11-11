<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TeamController extends AceController
{
    public function index(Request $request): JsonResponse
    {
        $team = $this->tenant($request)->users()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email', 'users.is_active']);
        return response()->json(['data' => $team->map(fn ($user) => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_active' => $user->is_active, 'role' => $user->pivot->role,
        ]), 'meta' => [
            'seat_limit' => (int) $this->tenant($request)->seat_limit,
            'seats_used' => $team->count(),
            'can_manage' => $this->role($request) === 'owner',
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);
        // Only owner may create team accounts; role does not affect this rule.
        $user = DB::transaction(function () use ($request, $data) {
            // Lock tenant row so concurrent create requests cannot exceed the licensed seats.
            $tenant = \App\Models\Tenant::query()->whereKey($this->tenant($request)->id)
                ->lockForUpdate()->firstOrFail();
            $used = DB::table('tenant_user')->where('tenant_id', $tenant->id)->count();
            if ($used >= (int) $tenant->seat_limit) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'team' => "Limite de {$tenant->seat_limit} usuário(s) atingido. Solicite ao suporte DEV o aumento da equipe.",
                ]);
            }
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);
            $tenant->users()->attach($user->id, ['role' => $data['role']]);
            return $user;
        });
        $this->logAction($request, 'team.user.created');
        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $this->ownerOnly($request);
        $tenant = $this->tenant($request);
        $user = $tenant->users()->where('users.id', $member)->firstOrFail();
        abort_if($user->pivot->role === 'owner', 403, 'O proprietário não pode ser alterado por esta tela.');
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'member', 'viewer'])]]);
        // Only the tenant owner can change team roles.
        $tenant->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $this->logAction($request, 'team.role.updated');
        return response()->json(['message' => 'Permissão atualizada.']);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $this->ownerOnly($request);
        $tenant = $this->tenant($request);
        $user = $tenant->users()->where('users.id', $member)->firstOrFail();
        abort_if($user->pivot->role === 'owner' || $user->id === $request->user()->id, 403, 'Não é possível remover este vínculo.');
        // Only the tenant owner can remove team accounts.
        $tenant->users()->detach($user->id);
        $this->logAction($request, 'team.user.removed');
        return response()->json(['message' => 'Acesso à empresa removido.']);
    }
}
