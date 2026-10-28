<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceController extends AceController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenants = $user->tenants()->where('tenants.is_active', true)->orderBy('tenants.name')->get();
        return response()->json(['data' => $tenants->map(fn ($tenant) => [
            'id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug,
            'role' => $tenant->pivot->role, 'plan' => $tenant->plan ?? 'free', 'is_active' => (bool) $tenant->is_active,
        ])]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:140']]);
        $tenant = $this->tenant($request);
        $tenant->update($data);
        $this->logAction($request, 'workspace.updated');
        return response()->json(['data' => ['id' => $tenant->id, 'name' => $tenant->name]]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->ownerOnly($request);
        $validated = $request->validate(['name' => ['required', 'string', 'max:140']]);
        $tenant = DB::transaction(function () use ($validated, $request) {
            $tenant = Tenant::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']).'-'.Str::lower(Str::random(8)),
                'is_active' => true,
            ]);
            $tenant->users()->attach($request->user()->id, ['role' => 'owner']);
            return $tenant;
        });
        // Evento referente à nova empresa, sem dados sensíveis.
        $this->logAction($request, 'workspace.created');
        return response()->json(['data' => ['id' => $tenant->id, 'name' => $tenant->name]], 201);
    }
}
