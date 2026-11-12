<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceAd;
use App\Models\DevAdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AceAdController extends Controller
{
    private const PLACEMENTS = ['after_features', 'after_control', 'before_plans_left', 'before_plans_right', 'footer'];
    private const PROVIDERS = ['direct', 'google', 'html'];

    public function published(): JsonResponse
    {
        // Um criativo por posição: evita excesso e layout instável.
        $campaigns = AceAd::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('priority')->orderByDesc('id')->get();
        $slots = [];
        foreach ($campaigns as $campaign) {
            $placement = $campaign->placement === 'before_plans' ? 'before_plans_left' : $campaign->placement;
            if (isset($slots[$placement])) continue;
            if ($campaign->provider === 'direct' && !$campaign->image_path && !$this->extraImages($campaign)->count()) continue;
            if ($campaign->provider === 'google' && (!$campaign->google_client || !$campaign->google_slot)) continue;
            if ($campaign->provider === 'html' && !$campaign->html_code) continue;
            $slots[$placement] = $this->publicData($campaign);
        }
        return response()->json(['data' => $slots])->header('Cache-Control', 'no-store');
    }

    public function image(AceAd $ad): BinaryFileResponse
    {
        abort_unless($this->live($ad) && $ad->provider === 'direct', 404);
        return $this->serveImage($ad);
    }

    public function embed(AceAd $ad): \Illuminate\Http\Response
    {
        abort_unless($this->live($ad) && $ad->provider === 'html' && $ad->html_code, 404);
        // O documento é executado num iframe sandbox sem acesso à origem do ACE.
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:transparent">'.$ad->html_code.'</body></html>';
        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function creativeImage(AceAd $ad, int $creative): BinaryFileResponse
    {
        abort_unless($this->live($ad) && $ad->provider === 'direct', 404);
        return $this->serveCreative($ad, $creative);
    }

    public function adminCreativeImage(AceAd $ad, int $creative): BinaryFileResponse
    {
        return $this->serveCreative($ad, $creative);
    }

    private function serveCreative(AceAd $ad, int $creative): BinaryFileResponse
    {
        $path = DB::table('ace_ad_creatives')->where('ad_id', $ad->id)->where('id', $creative)->value('image_path');
        abort_unless($path, 404);
        return $this->servePath($path);
    }

    public function adminImage(AceAd $ad): BinaryFileResponse
    {
        return $this->serveImage($ad);
    }

    private function live(AceAd $ad): bool
    {
        return $ad->is_active && (!$ad->starts_at || $ad->starts_at->lte(now()))
            && (!$ad->ends_at || $ad->ends_at->gt(now()));
    }

    private function serveImage(AceAd $ad): BinaryFileResponse
    {
        abort_unless($ad->image_path, 404);
        return $this->servePath($ad->image_path);
    }

    private function servePath(string $path): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);
        $mime = ['png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp']
            [strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        abort_unless($mime, 404);
        return response()->file($disk->path($path), [
            'Content-Type'=>$mime,
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'none'; sandbox",
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data'=>AceAd::query()->orderByDesc('id')->get()
            ->map(fn (AceAd $ad)=>$this->adminData($ad))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->saveImage($request);
        }
        $data['created_by'] = $request->user()->id;
        $ad = AceAd::create($data);
        $this->syncCreatives($request, $ad);
        $this->audit($request, 'created', $ad);
        return response()->json(['data'=>$this->adminData($ad), 'message'=>'Campanha cadastrada.'], 201);
    }

    public function update(Request $request, AceAd $ad): JsonResponse
    {
        $data = $this->validatePayload($request, $ad);
        if ($request->hasFile('image')) {
            $data['image_path'] = $this->saveImage($request);
        } elseif ($request->boolean('remove_image') || $data['provider'] !== 'direct') {
            $data['image_path'] = null; // Não apaga arquivo antigo.
        }
        $ad->update($data);
        $this->syncCreatives($request, $ad);
        $this->audit($request, 'updated', $ad);
        return response()->json(['data'=>$this->adminData($ad->fresh()), 'message'=>'Campanha atualizada.']);
    }

    public function destroy(Request $request, AceAd $ad): JsonResponse
    {
        $ad->delete(); // Soft delete, sem apagar mídia ou campanhas anteriores.
        $this->audit($request, 'deleted', $ad);
        return response()->json(['message'=>'Campanha removida com histórico preservado.']);
    }

    private function extraImages(AceAd $ad): \Illuminate\Support\Collection
    {
        return DB::table('ace_ad_creatives')->where('ad_id', $ad->id)->orderBy('sort_order')->orderBy('id')->get();
    }

    private function syncCreatives(Request $request, AceAd $ad): void
    {
        // Apenas desvincula imagens na edição; arquivos físicos permanecem preservados.
        $remove = array_map('intval', $request->input('remove_creatives', []));
        if ($remove) DB::table('ace_ad_creatives')->where('ad_id', $ad->id)->whereIn('id', $remove)->delete();
        if ($ad->provider !== 'direct') return;
        $files = $request->file('images', []);
        foreach ($files as $index => $file) {
            $path = $file->store('ace/ads', 'local');
            abort_unless($path, 500, 'Não foi possível armazenar a imagem.');
            DB::table('ace_ad_creatives')->insert([
                'ad_id'=>$ad->id, 'image_path'=>$path, 'sort_order'=>$index + 1,
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
        }
    }

    private function saveImage(Request $request): string
    {
        $path = $request->file('image')->store('ace/ads', 'local');
        abort_unless($path, 500, 'Não foi possível armazenar a imagem.');
        return $path;
    }

    private function validatePayload(Request $request, ?AceAd $ad = null): array
    {
        $url = trim((string) $request->input('destination_url', ''));
        if ($url !== '' && !preg_match('~^https?://~i', $url) && !str_starts_with($url, '/')) {
            $request->merge(['destination_url' => 'https://'.$url]);
        }
        $data = $request->validate([
            'name'=>['required', 'string', 'max:140'],
            'provider'=>['required', Rule::in(self::PROVIDERS)],
            'placement'=>['required', Rule::in(self::PLACEMENTS)],
            'headline'=>['nullable', 'string', 'max:160'],
            'description'=>['nullable', 'string', 'max:240'],
            'image'=>['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'images'=>['nullable','array','max:10'],
            'images.*'=>['file','image','mimes:png,jpg,jpeg,webp','max:4096'],
            'remove_creatives'=>['nullable','array'],
            'remove_creatives.*'=>['integer','min:1'],
            'html_code'=>['nullable','string','max:16000'],
            'remove_image'=>['sometimes', 'boolean'],
            'destination_url'=>['nullable', 'string', 'max:500', function ($attribute, $value, $fail) {
                if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_contains($value, chr(92))) return;
                $url = parse_url($value);
                if (!$url || !in_array(strtolower($url['scheme'] ?? ''), ['http','https'], true)
                    || empty($url['host']) || !filter_var($value, FILTER_VALIDATE_URL)) {
                    $fail('Informe um domínio, URL HTTP(S) ou caminho interno iniciado por /.');
                }
            }],
            'google_client'=>['nullable','regex:/^ca-pub-[0-9]{16}$/'],
            'google_slot'=>['nullable','regex:/^[0-9]{8,20}$/'],
            'priority'=>['required','integer','min:0','max:9999'],
            'is_active'=>['required','boolean'],
            'starts_at'=>['nullable','date'],
            'ends_at'=>['nullable','date'],
        ]);
        if ($data['provider'] === 'direct') {
            $existingCount = $ad && $ad->provider === 'direct'
                ? ($ad->image_path && !$request->boolean('remove_image') ? 1 : 0)
                    + $this->extraImages($ad)->whereNotIn('id', array_map('intval', $request->input('remove_creatives', [])))->count()
                : 0;
            $incomingCount = ($request->hasFile('image') ? 1 : 0) + count($request->file('images', []));
            if ($existingCount + $incomingCount > 11) {
                throw ValidationException::withMessages(['images'=>'Limite de 11 imagens por campanha.']);
            }
            if (!$request->hasFile('image') && !$request->hasFile('images') && (!$ad || $ad->provider !== 'direct' || (!$ad->image_path && !$this->extraImages($ad)->count()) || ($request->boolean('remove_image') && !$this->extraImages($ad)->count()))) {
                throw ValidationException::withMessages(['image'=>'Envie uma imagem para o anúncio.']);
            }
            $data['google_client'] = null;
            $data['google_slot'] = null;
            $data['html_code'] = null;
        } elseif ($data['provider'] === 'google') {
            if (empty($data['google_client']) || empty($data['google_slot'])) {
                throw ValidationException::withMessages(['google_client'=>'Preencha o Publisher ID e o Slot ID do AdSense.']);
            }
            $data['destination_url'] = null;
            $data['headline'] = null;
            $data['description'] = null;
            $data['html_code'] = null;
        } else {
            if (trim((string) ($data['html_code'] ?? '')) === '') {
                throw ValidationException::withMessages(['html_code'=>'Cole o código HTML do anúncio.']);
            }
            $data['google_client'] = null;
            $data['google_slot'] = null;
            $data['destination_url'] = null;
            $data['headline'] = null;
            $data['description'] = null;
        }
        if (!empty($data['starts_at']) && !empty($data['ends_at'])
            && strtotime($data['ends_at']) <= strtotime($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at'=>'O término precisa ser posterior ao início.']);
        }
        unset($data['image'], $data['images'], $data['remove_creatives'], $data['remove_image']);
        return $data;
    }

    private function publicData(AceAd $ad): array
    {
        return [
            'id'=>$ad->id, 'provider'=>$ad->provider, 'placement'=>$ad->placement === 'before_plans' ? 'before_plans_left' : $ad->placement,
            'headline'=>$ad->headline, 'description'=>$ad->description,
            'destination_url'=>$ad->destination_url,
            'image_url'=>$ad->provider === 'direct' ? '/api/v1/public/ads/'.$ad->id.'/image' : null,
            'google_client'=>$ad->provider === 'google' ? $ad->google_client : null,
            'google_slot'=>$ad->provider === 'google' ? $ad->google_slot : null,
            'html_code'=>$ad->provider === 'html' ? $ad->html_code : null,
            'images'=>$ad->provider === 'direct' ? array_values(array_merge(
                $ad->image_path ? [['id'=>null,'url'=>'/api/v1/public/ads/'.$ad->id.'/image']] : [],
                $this->extraImages($ad)->map(fn ($item)=>['id'=>$item->id,'url'=>'/api/v1/public/ads/'.$ad->id.'/creatives/'.$item->id.'/image'])->all()
            )) : [],
        ];
    }

    private function adminData(AceAd $ad): array
    {
        return $this->publicData($ad) + [
            'name'=>$ad->name, 'priority'=>$ad->priority, 'is_active'=>$ad->is_active,
            'starts_at'=>$ad->starts_at?->toIso8601String(),
            'ends_at'=>$ad->ends_at?->toIso8601String(),
            'admin_image_url'=>$ad->image_path ? '/api/v1/dev/ads/'.$ad->id.'/image' : null,
            'admin_images'=>array_values(array_merge(
                $ad->image_path ? [['id'=>null,'url'=>'/api/v1/dev/ads/'.$ad->id.'/image']] : [],
                $this->extraImages($ad)->map(fn ($item)=>['id'=>$item->id,'url'=>'/api/v1/dev/ads/'.$ad->id.'/creatives/'.$item->id.'/image'])->all()
            )),
        ];
    }

    private function audit(Request $request, string $action, AceAd $ad): void
    {
        DevAdminAudit::create([
            'actor_user_id'=>$request->user()->id, 'tenant_id'=>null,
            'action'=>'dev.ad.'.$action.'.'.$ad->id, 'created_at'=>now(),
        ]);
    }
}
