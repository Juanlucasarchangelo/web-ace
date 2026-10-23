<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SiteController extends AceController
{
    private function query(Request $request)
    {
        return Site::query()->where('tenant_id', $this->tenant($request)->id);
    }

    private function rules(Request $request, ?Site $site = null): array
    {
        $tenantId = $this->tenant($request)->id;
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:180'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-zA-Z0-9.-]+$/', Rule::unique('sites', 'domain')->where('tenant_id', $tenantId)->ignore($site?->id)],
            'status' => ['required', Rule::in(['active', 'inactive', 'maintenance'])],
            'online_at' => ['nullable', 'date_format:Y-m-d'],
            'hosting_provider' => ['nullable', 'string', 'max:180'],
            'dns_primary' => ['nullable', 'string', 'max:253'],
            'dns_secondary' => ['nullable', 'string', 'max:253'],
            'ftp_host' => ['nullable', 'string', 'max:253'],
            'control_panel_url' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'store_url' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'registro_br' => ['sometimes', 'nullable', 'string', 'max:30000'],
            'social_links' => ['nullable', 'array'],
            'social_links.drive' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'social_links.youtube' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'social_links.facebook' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'social_links.linkedin' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'social_links.gmail' => ['nullable', 'string', 'max:255'],
            'social_links.instagram' => ['nullable', 'string', 'max:255'],
            'social_links.linktree' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'social_links.additional' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->query($request)->with('client:id,name');
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('domain', 'like', '%'.$search.'%');
            });
        }
        if (in_array($request->query('status'), ['active', 'inactive', 'maintenance'], true)) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }
        $results = $query->latest()->paginate(20);
        return response()->json(['data' => $results]);
    }

    public function show(Request $request, int $site): JsonResponse
    {
        $record = $this->query($request)->with(['client:id,name', 'accesses' => function ($q): void {
            $q->orderBy('category')->orderBy('label');
        }])->findOrFail($site);
        $data = $record->toArray();
        $data['has_registry'] = ! empty($record->registro_br);
        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->canWrite($request);
        $payload = $request->validate($this->rules($request));
        $payload['tenant_id'] = $this->tenant($request)->id;
        $payload['domain'] = Str::lower($payload['domain']);
        $record = Site::create($payload);
        $this->logAction($request, 'site.created');
        return response()->json(['data' => $record], 201);
    }

    public function update(Request $request, int $site): JsonResponse
    {
        $this->canWrite($request);
        $record = $this->query($request)->findOrFail($site);
        $payload = $request->validate($this->rules($request, $record));
        $payload['domain'] = Str::lower($payload['domain']);
        // Omitir registro_br preserva o valor cifrado existente.
        $record->update($payload);
        $this->logAction($request, 'site.updated');
        return response()->json(['data' => $record->fresh()]);
    }

    public function revealRegistry(Request $request, int $site): JsonResponse
    {
        $this->canWrite($request);
        $record = $this->query($request)->findOrFail($site);
        $this->logAction($request, 'site.registry.revealed');
        return response()->json(['data' => ['value' => $record->registro_br]]);
    }

    public function destroy(Request $request, int $site): JsonResponse
    {
        $this->canWrite($request);
        $record = $this->query($request)->findOrFail($site);
        $record->delete();
        $this->logAction($request, 'site.deleted');
        return response()->json(['message' => 'Site excluído.']);
    }
}
