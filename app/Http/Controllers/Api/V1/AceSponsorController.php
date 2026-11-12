<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceSponsor;
use App\Models\DevAdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AceSponsorController extends Controller
{
    // Only active sponsors are exposed to visitors. No internal file paths or editor IDs.
    public function published(): JsonResponse
    {
        return response()->json(['data' => AceSponsor::query()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->limit(40)->get()
            ->map(fn (AceSponsor $s): array => $this->publicData($s))->values()]);
    }

    public function logo(AceSponsor $sponsor): BinaryFileResponse
    {
        abort_unless($sponsor->is_active, 404);
        return $this->sendLogo($sponsor);
    }

    public function adminLogo(AceSponsor $sponsor): BinaryFileResponse
    {
        // This action is reachable only through auth:sanctum + dev.only.
        return $this->sendLogo($sponsor);
    }

    private function sendLogo(AceSponsor $sponsor): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_unless($sponsor->logo_path && $disk->exists($sponsor->logo_path), 404);

        // Extension was restricted to server-validated raster formats at upload.
        $extension = strtolower(pathinfo($sponsor->logo_path, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$extension] ?? null;
        abort_unless($mime, 404);
        return response()->file($disk->path($sponsor->logo_path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=300, nosniff',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    // These CRUD actions are registered exclusively under the dev.only middleware.
    public function index(): JsonResponse
    {
        return response()->json(['data' => AceSponsor::query()->orderBy('sort_order')->orderByDesc('id')
            ->get()->map(fn (AceSponsor $s): array => $this->adminData($s))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateSponsor($request, true);
        $logo = $request->file('logo');
        $path = $logo->store('ace/sponsors', 'local');
        abort_unless($path, 500, 'Falha ao armazenar o logotipo.');
        $item = AceSponsor::create([
            'name' => $data['name'], 'logo_path' => $path,
            'website_url' => $data['website_url'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $request->user()->id,
        ]);
        $this->audit($request, 'created', $item);
        return response()->json(['data' => $this->adminData($item), 'message' => 'Patrocinador cadastrado.'], 201);
    }

    public function update(Request $request, AceSponsor $sponsor): JsonResponse
    {
        $data = $this->validateSponsor($request, false);
        $values = collect($data)->except('logo')->all();
        if ($request->hasFile('logo')) {
            $values['logo_path'] = $request->file('logo')->store('ace/sponsors', 'local');
            abort_unless($values['logo_path'], 500, 'Falha ao armazenar o logotipo.');
            // Previous image intentionally kept in storage for recoverability.
        }
        $sponsor->update($values);
        $this->audit($request, 'updated', $sponsor);
        return response()->json(['data' => $this->adminData($sponsor->fresh()), 'message' => 'Patrocinador atualizado.']);
    }

    public function destroy(Request $request, AceSponsor $sponsor): JsonResponse
    {
        $sponsor->delete(); // Soft delete: retains DB row and logo; never cascades into any tenant.
        $this->audit($request, 'deleted', $sponsor);
        return response()->json(['message' => 'Patrocinador removido da vitrine, com histórico preservado.']);
    }

    private function audit(Request $request, string $action, AceSponsor $sponsor): void
    {
        DevAdminAudit::create([
            'actor_user_id' => $request->user()->id,
            'tenant_id' => null,
            'action' => 'dev.sponsor.'.$action.'.'.$sponsor->id,
            'created_at' => now(),
        ]);
    }

    private function validateSponsor(Request $request, bool $creating): array
    {
        $url = trim((string) $request->input('website_url', ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url) && !str_starts_with($url, '/')) {
            $request->merge(['website_url' => 'https://' . $url]);
        }
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:140'],
            'logo' => [$creating ? 'required' : 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'website_url' => ['sometimes', 'nullable', 'url:http,https', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:240'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            'is_active' => ['sometimes', Rule::in(['0','1',0,1,true,false])],
        ]);
    }

    private function publicData(AceSponsor $s): array
    {
        return ['id' => $s->id, 'name' => $s->name,
            'logo_url' => '/api/v1/public/sponsors/'.$s->id.'/logo',
            'website_url' => $s->website_url];
    }

    private function adminData(AceSponsor $s): array
    {
        return $this->publicData($s) + [
            'admin_logo_url' => '/api/v1/dev/sponsors/'.$s->id.'/logo',
            'description' => $s->description, 'sort_order' => $s->sort_order,
            'is_active' => $s->is_active, 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
