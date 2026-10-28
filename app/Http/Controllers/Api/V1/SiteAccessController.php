<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Site;
use App\Models\SiteAccess;
use App\Support\UrlInputNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteAccessController extends AceController
{
    private function site(Request $request, int $site): Site
    {
        return Site::where('tenant_id', $this->tenant($request)->id)->findOrFail($site);
    }

    private function access(Request $request, Site $site, int $access): SiteAccess
    {
        return $site->accesses()->where('tenant_id', $this->tenant($request)->id)->findOrFail($access);
    }

    private function rules(bool $creating): array
    {
        return [
            'category' => ['required', Rule::in(['email', 'store', 'social', 'hosting', 'ftp', 'other'])],
            'label' => ['required', 'string', 'max:140'],
            'service' => ['nullable', 'string', 'max:120'],
            'url' => ['nullable', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => [$creating ? 'nullable' : 'sometimes', 'nullable', 'string', 'max:30000'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function store(Request $request, int $site): JsonResponse
    {
        $this->canWrite($request);
        $record = $this->site($request, $site);
        UrlInputNormalizer::inRequest($request, ['url']);
        $payload = $request->validate($this->rules(true));
        $access = $record->accesses()->create($payload + ['tenant_id' => $this->tenant($request)->id]);
        $this->logAction($request, 'access.created');
        return response()->json(['data' => $access], 201);
    }

    public function update(Request $request, int $site, int $access): JsonResponse
    {
        $this->canWrite($request);
        $item = $this->access($request, $this->site($request, $site), $access);
        UrlInputNormalizer::inRequest($request, ['url']);
        $item->update($request->validate($this->rules(false)));
        $this->logAction($request, 'access.updated');
        return response()->json(['data' => $item->fresh()]);
    }

    public function reveal(Request $request, int $site, int $access): JsonResponse
    {
        $this->canWrite($request);
        $item = $this->access($request, $this->site($request, $site), $access);
        $this->logAction($request, 'access.secret.revealed');
        return response()->json(['data' => ['secret' => $item->secret]]);
    }

    public function destroy(Request $request, int $site, int $access): JsonResponse
    {
        $this->canWrite($request);
        $item = $this->access($request, $this->site($request, $site), $access);
        $item->delete();
        $this->logAction($request, 'access.deleted');
        return response()->json(['message' => 'Acesso excluído.']);
    }
}
