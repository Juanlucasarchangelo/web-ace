<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends AceController
{
    private function query(Request $request)
    {
        return Client::query()->where('tenant_id', $this->tenant($request)->id);
    }

    private function rules(Request $request, ?Client $client = null): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('clients', 'code')->where('tenant_id', $this->tenant($request)->id)->ignore($client?->id)],
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'person_type' => ['required', Rule::in(['company', 'person'])],
            'document' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'address_street' => ['nullable', 'string', 'max:180'],
            'address_number' => ['nullable', 'string', 'max:30'],
            'address_extra' => ['nullable', 'string', 'max:100'],
            'address_district' => ['nullable', 'string', 'max:120'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_state' => ['nullable', 'string', 'max:50'],
            'address_zip' => ['nullable', 'string', 'max:25'],
            'address_country' => ['nullable', 'string', 'max:80'],
            'started_at' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'preferred_cloud' => ['nullable', Rule::in(['google_drive', 'icloud', 'mega', 's3', 'onedrive', 'dropbox', 'other'])],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->query($request)->withCount('sites');
        if ($search = trim((string) $request->query('search', ''))) {
            $q->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%')
                    ->orWhere('document', 'like', '%'.$search.'%');
            });
        }
        if (in_array($request->query('status'), ['active', 'inactive'], true)) {
            $q->where('status', $request->query('status'));
        }
        return response()->json(['data' => $q->orderBy('name')->paginate(20)]);
    }

    public function show(Request $request, int $client): JsonResponse
    {
        return response()->json(['data' => $this->query($request)->withCount('sites')->findOrFail($client)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->canWrite($request);
        $client = Client::create($request->validate($this->rules($request)) + ['tenant_id' => $this->tenant($request)->id]);
        $this->logAction($request, 'client.created');
        return response()->json(['data' => $client], 201);
    }

    public function update(Request $request, int $client): JsonResponse
    {
        $this->canWrite($request);
        $record = $this->query($request)->findOrFail($client);
        $record->update($request->validate($this->rules($request, $record)));
        $this->logAction($request, 'client.updated');
        return response()->json(['data' => $record->fresh()]);
    }

    public function destroy(Request $request, int $client): JsonResponse
    {
        $this->canWrite($request);
        $record = $this->query($request)->findOrFail($client);
        abort_if($record->sites()->exists(), 422, 'Este cliente possui sites. Reatribua ou exclua os sites antes.');
        $record->delete();
        $this->logAction($request, 'client.deleted');
        return response()->json(['message' => 'Cliente excluído.']);
    }
}
