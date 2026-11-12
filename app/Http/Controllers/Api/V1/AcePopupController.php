<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcePopup;
use App\Models\DevAdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AcePopupController extends Controller
{
    private const TYPES = ['text', 'image', 'offer', 'notice'];
    private const PLACEMENTS = ['home', 'login', 'signup', 'dashboard'];
    private const TRIGGERS = ['immediate', 'delay', 'scroll', 'exit'];
    private const FREQUENCIES = ['always', 'session', 'day', 'once'];

    // Chamadas públicas nunca retornam anúncios pausados, expirados ou privados do DEV.
    public function published(Request $request): JsonResponse
    {
        return $this->publishedFor($request, false);
    }

    public function publishedMember(Request $request): JsonResponse
    {
        return $this->publishedFor($request, true);
    }

    private function publishedFor(Request $request, bool $authenticated): JsonResponse
    {
        $placement = $request->validate(['placement' => ['required', Rule::in(self::PLACEMENTS)]])['placement'];
        abort_if($authenticated !== ($placement === 'dashboard'), 404);

        $items = AcePopup::query()
            ->where('is_active', true)
            ->whereJsonContains('placements', $placement)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('priority')->orderByDesc('id')->limit(30)->get();

        return response()->json(['data' => $items->map(fn (AcePopup $item) => $this->publicData($item, $authenticated))->values()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function image(AcePopup $popup): BinaryFileResponse
    {
        // A mesma checagem de vigência e publicação protege o arquivo original.
        abort_unless($this->isPublished($popup) && array_intersect((array) $popup->placements, ['home', 'login', 'signup']), 404);
        return $this->serveImage($popup);
    }

    public function memberImage(AcePopup $popup): BinaryFileResponse
    {
        abort_unless($this->isPublished($popup) && in_array('dashboard', (array) $popup->placements, true), 404);
        return $this->serveImage($popup);
    }

    public function adminImage(AcePopup $popup): BinaryFileResponse
    {
        // Rota acessível somente com auth:sanctum + dev.only.
        return $this->serveImage($popup);
    }

    private function isPublished(AcePopup $popup): bool
    {
        return $popup->is_active && (!$popup->starts_at || $popup->starts_at->lte(now()))
            && (!$popup->ends_at || $popup->ends_at->gt(now()));
    }

    private function serveImage(AcePopup $popup): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_unless($popup->image_path && $disk->exists($popup->image_path), 404);
        $extension = strtolower(pathinfo($popup->image_path, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$extension] ?? null;
        abort_unless($mime, 404);

        return response()->file($disk->path($popup->image_path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => AcePopup::query()->orderByDesc('created_at')
            ->orderByDesc('id')->get()->map(fn (AcePopup $popup) => $this->adminData($popup))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request);
        }
        $data['created_by'] = $request->user()->id;
        $popup = AcePopup::create($data);
        $this->audit($request, 'created', $popup);
        return response()->json(['data' => $this->adminData($popup), 'message' => 'Popup cadastrado.'], 201);
    }

    public function update(Request $request, AcePopup $popup): JsonResponse
    {
        $data = $this->validatePayload($request, $popup);
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storeImage($request);
            // Arquivo anterior preservado; nunca apagamos mídia na atualização.
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null; // Desvincula, sem apagar o arquivo original.
        }
        $popup->update($data);
        $this->audit($request, 'updated', $popup);
        return response()->json(['data' => $this->adminData($popup->fresh()), 'message' => 'Popup atualizado.']);
    }

    public function destroy(Request $request, AcePopup $popup): JsonResponse
    {
        $popup->delete(); // Soft delete: mantém linha e mídia; sem efeito em outras tabelas.
        $this->audit($request, 'deleted', $popup);
        return response()->json(['message' => 'Popup removido com histórico preservado.']);
    }

    private function storeImage(Request $request): string
    {
        $path = $request->file('image')->store('ace/popups', 'local');
        abort_unless($path, 500, 'Não foi possível guardar a imagem do popup.');
        return $path;
    }

    private function validatePayload(Request $request, ?AcePopup $popup = null): array
    {
        $url = trim((string) $request->input('cta_url', ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url) && !str_starts_with($url, '/')) {
            $request->merge(['cta_url' => 'https://' . $url]);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'badge_text' => ['nullable', 'string', 'max:70'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
            'cta_label' => ['nullable', 'string', 'max:70'],
            'cta_url' => ['nullable', 'string', 'max:500', function ($attribute, $value, $fail): void {
                // Permite HTTPS/HTTP e caminhos internos relativos, jamais javascript: ou //domínio.
                if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_contains($value, '\\') && !preg_match('/[\x00-\x1f]/', $value)) return;
                $parts = parse_url($value);
                if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    || empty($parts['host']) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                    $fail('Informe um endereço HTTP(S) ou um caminho interno iniciado por /.');
                }
            }],
            'placements' => ['required', 'array', 'min:1', 'max:4'],
            'placements.*' => ['required', 'distinct', Rule::in(self::PLACEMENTS)],
            'trigger' => ['required', Rule::in(self::TRIGGERS)],
            'delay_seconds' => ['required', 'integer', 'min:0', 'max:180'],
            'scroll_percent' => ['required', 'integer', 'min:10', 'max:90'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'priority' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        // Formulário completo evita estados parciais ao trocar o tipo.
        if (in_array($data['type'], ['text', 'offer', 'notice'], true) && blank($data['title'] ?? null)) {
            throw ValidationException::withMessages(['title' => 'Informe um título para esse tipo de popup.']);
        }
        if ($data['type'] === 'image' && !$request->hasFile('image') && (!$popup?->image_path || $request->boolean('remove_image'))) {
            throw ValidationException::withMessages(['image' => 'Envie a imagem que será exibida.']);
        }
        if ($data['type'] === 'text' && blank($data['description'] ?? null)) {
            throw ValidationException::withMessages(['description' => 'Informe a descrição do popup de texto.']);
        }
        if (!empty($data['starts_at']) && !empty($data['ends_at'])
            && \Illuminate\Support\Carbon::parse($data['ends_at'])->lte(\Illuminate\Support\Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'O término deve ser posterior ao início.']);
        }
        unset($data['image'], $data['remove_image']);
        return $data;
    }

    private function publicData(AcePopup $popup, bool $member = false): array
    {
        return [
            'id' => $popup->id, 'type' => $popup->type, 'title' => $popup->title,
            'description' => $popup->description, 'badge_text' => $popup->badge_text,
            'image_url' => $popup->image_path ? '/api/v1/'.($member ? '' : 'public/').'popups/'.$popup->id.'/image' : null,
            'cta_label' => $popup->cta_label, 'cta_url' => $popup->cta_url,
            'trigger' => $popup->trigger, 'delay_seconds' => $popup->delay_seconds,
            'scroll_percent' => $popup->scroll_percent, 'frequency' => $popup->frequency,
            'version' => $popup->updated_at?->timestamp,
        ];
    }

    private function adminData(AcePopup $popup): array
    {
        return $this->publicData($popup) + [
            'admin_image_url' => $popup->image_path ? '/api/v1/dev/popups/'.$popup->id.'/image' : null,
            'placements' => $popup->placements, 'priority' => $popup->priority,
            'is_active' => $popup->is_active,
            'starts_at' => $popup->starts_at?->toIso8601String(),
            'ends_at' => $popup->ends_at?->toIso8601String(),
            'created_at' => $popup->created_at?->toIso8601String(),
        ];
    }

    private function audit(Request $request, string $action, AcePopup $popup): void
    {
        DevAdminAudit::create([
            'actor_user_id' => $request->user()->id, 'tenant_id' => null,
            'action' => 'dev.popup.'.$action.'.'.$popup->id, 'created_at' => now(),
        ]);
    }
}
