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
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->canWrite($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);
        if ($data['role'] === 'admin') $this->ownerOnly($request);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => Str::lower($data['email']),
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);
            $this->tenant($request)->users()->attach($user->id, ['role' => $data['role']]);
            return $user;
        });
        $this->logAction($request, 'team.user.created');
        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]], 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $this->canWrite($request);
        $tenant = $this->tenant($request);
        $user = $tenant->users()->where('users.id', $member)->firstOrFail();
        abort_if($user->pivot->role === 'owner', 403, 'O proprietário não pode ser alterado por esta tela.');
        $data = $request->validate(['role' => ['required', Rule::in(['admin', 'member', 'viewer'])]]);
        if ($user->pivot->role === 'admin' || $data['role'] === 'admin') $this->ownerOnly($request);
        $tenant->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $this->logAction($request, 'team.role.updated');
        return response()->json(['message' => 'Permissão atualizada.']);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $this->canWrite($request);
        $tenant = $this->tenant($request);
        $user = $tenant->users()->where('users.id', $member)->firstOrFail();
        abort_if($user->pivot->role === 'owner' || $user->id === $request->user()->id, 403, 'Não é possível remover este vínculo.');
        if ($user->pivot->role === 'admin') $this->ownerOnly($request);
        $tenant->users()->detach($user->id);
        $this->logAction($request, 'team.user.removed');
        return response()->json(['message' => 'Acesso à empresa removido.']);
    }
}
