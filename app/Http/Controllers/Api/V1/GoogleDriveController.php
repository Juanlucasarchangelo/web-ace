<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditEvent;
use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveService;
use App\Services\GoogleDriveHealth;
use App\Support\PlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleDriveController extends AceController
{
    private function permitted(Request $request, bool $write = false, bool $create = false): void
    {
        abort_unless(PlanFeatures::cloud($this->tenant($request)), 403, 'Google Drive requer o plano Cloud ou Pro.');
        if ($write) $this->canWrite($request);
        if ($create) $this->canCreate($request);
    }

    private function account(Request $request, bool $write = false, bool $create = false): GoogleDriveConnection
    {
        $this->permitted($request, $write, $create);
        $query = GoogleDriveConnection::where('tenant_id', $this->tenant($request)->id);
        $id = $request->input('account_id');
        if ($id !== null && $id !== '') {
            abort_unless(ctype_digit((string) $id), 422, 'Conta de armazenamento inválida.');
            $query->whereKey((int) $id);
        }
        return $query->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['drive' => 'Conecte uma conta Google Drive antes de continuar.']);
    }

    private function accountSummary(GoogleDriveConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => 'google',
            'email' => $connection->email,
            'connected_at' => $connection->created_at?->toIso8601String(),
            'scope' => $connection->scope,
            'health_status' => $connection->health_status,
            'error_code' => $connection->last_error_code,
            'health_message' => $connection->last_error_code
                ? GoogleDriveHealth::message($connection->last_error_code)
                : ($connection->health_status === 'connected' ? 'Conexão verificada com o Google.' : 'Conexão ainda não verificada.'),
            'last_checked_at' => $connection->last_checked_at?->toIso8601String(),
        ];
    }

    public function status(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $enabled = PlanFeatures::cloud($tenant);
        $accounts = $enabled ? GoogleDriveConnection::where('tenant_id', $tenant->id)->orderBy('id')->get() : collect();
        $first = $accounts->first();
        return response()->json(['data' => [
            'plan' => $tenant->plan ?? 'free',
            'cloud_enabled' => $enabled,
            'ai_entitled' => PlanFeatures::ai($tenant),
            'connected' => $accounts->isNotEmpty(), // Legacy account-present flag; not proof that the token works.
            'verified_connected' => $accounts->contains(fn ($a) => $a->health_status === 'connected'),
            'connections_needing_attention' => $accounts->filter(fn ($a) => in_array($a->health_status, ['error', 'reconnect_required'], true))->count(),
            'can_manage' => in_array($this->role($request), ['owner', 'admin'], true),
            'can_create' => in_array($this->role($request), ['owner', 'admin', 'member'], true),
            'email' => $first?->email,
            'scope' => $first?->scope,
            'accounts' => $accounts->map(fn ($item) => $this->accountSummary($item))->values()->all(),
            'configured' => (bool) (config('services.google_drive.client_id') && config('services.google_drive.client_secret')),
        ]]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->permitted($request);
        return response()->json(['data' => GoogleDriveConnection::where('tenant_id', $this->tenant($request)->id)
            ->orderBy('id')->get()->map(fn ($item) => $this->accountSummary($item))->values()->all()]);
    }

    public function checkAccount(Request $request, string $accountId, GoogleDriveHealth $health): JsonResponse
    {
        $this->permitted($request, true);
        abort_unless(ctype_digit($accountId), 422, 'Conta inválida.');
        $account = GoogleDriveConnection::where('tenant_id', $this->tenant($request)->id)
            ->findOrFail((int) $accountId);
        return response()->json(['data' => $health->check($account)]);
    }

    public function authorize(Request $request): JsonResponse
    {
        $this->permitted($request, false, true);
        abort_unless(
            config('services.google_drive.client_id') && config('services.google_drive.client_secret'),
            503,
            'Google Drive não configurado pelo administrador do ACE.'
        );
        $state = bin2hex(random_bytes(32));
        Cache::put('ace_drive_oauth:'.hash('sha256', $state), [
            'user_id' => $request->user()->id,
            'tenant_id' => $this->tenant($request)->id,
        ], now()->addMinutes(10));
        $params = http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => config('services.google_drive.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email '.config('services.google_drive.scope'),
            'access_type' => 'offline',
            'prompt' => 'select_account consent',
            'state' => $state,
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
        return response()->json(['data' => ['url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.$params]]);
    }

    public function callback(Request $request)
    {
        $user = $request->user();
        if (! $user) return redirect('/nuvem?drive=invalid_state');
        $state = (string) $request->query('state', '');
        $pending = $state ? Cache::pull('ace_drive_oauth:'.hash('sha256', $state)) : null;
        if (! is_array($pending) || (string) ($pending['user_id'] ?? '') !== (string) $user->id) {
            if (is_array($pending) && ! empty($pending['tenant_id'])) {
                app(GoogleDriveHealth::class)->failure((string) $pending['tenant_id'], null, 'oauth_state_error', 'google_oauth');
            }
            Log::warning('ACE Google OAuth: state inválido ou expirado.');
            return redirect('/nuvem?drive=invalid_state');
        }
        $tenant = $user->tenants()->where('tenants.id', $pending['tenant_id'])->where('tenants.is_active', true)->first();
        if (! $tenant || ! in_array($tenant->pivot->role, ['owner', 'admin', 'member'], true) || ! $tenant->pivot->is_active || ! PlanFeatures::cloud($tenant)) {
            return redirect('/nuvem?drive=denied');
        }
        if ($request->query('error') || ! $request->filled('code')) {
            app(GoogleDriveHealth::class)->failure($tenant->id, null, 'access_denied', 'google_oauth');
            return redirect('/nuvem?drive=cancelled');
        }

        try {
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_drive.client_id'),
                'client_secret' => config('services.google_drive.client_secret'),
                'redirect_uri' => config('services.google_drive.redirect'),
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
            ]);
        } catch (\Throwable $exception) {
            Log::error('ACE Google OAuth: falha de comunicação.', ['type' => get_class($exception)]);
            app(GoogleDriveHealth::class)->failure($tenant->id, null, 'network_error', 'google_oauth');
            return redirect('/nuvem?drive=token_error');
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            $code = app(GoogleDriveHealth::class)->codeFor($response);
            app(GoogleDriveHealth::class)->failure($tenant->id, null, $code, 'google_oauth');
            Log::error('ACE Google OAuth: troca de token recusada.', [
                'http_status' => $response->status(),
                'google_error' => Str::limit((string) $response->json('error', 'unknown'), 100),
            ]);
            return redirect('/nuvem?drive=token_error&reason='.rawurlencode($code));
        }

        $accessToken = $response->json('access_token');
        try {
            $profile = Http::withToken($accessToken)->timeout(15)
                ->get('https://openidconnect.googleapis.com/v1/userinfo');
        } catch (\Throwable $exception) {
            Log::warning('ACE Google OAuth: falha ao identificar a conta.', ['type' => get_class($exception)]);
            app(GoogleDriveHealth::class)->failure($tenant->id, null, 'oauth_profile_error', 'google_oauth');
            return redirect('/nuvem?drive=profile_error');
        }
        $email = $profile->successful() ? $profile->json('email') : null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('ACE Google OAuth: e-mail ausente.', ['http_status' => $profile->status()]);
            app(GoogleDriveHealth::class)->failure($tenant->id, null, 'oauth_profile_error', 'google_oauth');
            return redirect('/nuvem?drive=profile_error');
        }

        $existing = GoogleDriveConnection::where('tenant_id', $tenant->id)->where('email', $email)->first();
        $refreshToken = $response->json('refresh_token');
        if ((! is_string($refreshToken) || $refreshToken === '') && $existing) {
            $refreshToken = $existing->refresh_token;
        }
        if (! is_string($refreshToken) || $refreshToken === '') {
            Log::warning('ACE Google OAuth: refresh_token ausente.', ['http_status' => $response->status()]);
            app(GoogleDriveHealth::class)->failure($tenant->id, null, 'missing_refresh_token', 'google_oauth');
            return redirect('/nuvem?drive=token_error');
        }

        $connectedAccount = GoogleDriveConnection::updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'connected_by' => $user->id,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
                'scope' => (string) $response->json('scope', ''),
            ]
        );
        app(GoogleDriveHealth::class)->success($connectedAccount);
        AuditEvent::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'cloud.google.connected',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'created_at' => now(),
        ]);
        return redirect('/nuvem?drive=connected');
    }

    public function files(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $data = $request->validate(['page_token' => ['nullable', 'string', 'max:2000']]);
        return response()->json(['data' => $drive->listFiles($this->account($request), $data['page_token'] ?? null)]);
    }

    public function folder(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:180']]);
        $result = $drive->folder($this->account($request, false, true), $data['name']);
        $this->logAction($request, 'cloud.google.folder.created');
        return response()->json(['data' => $result], 201);
    }

    public function upload(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $result = $drive->upload($this->account($request, false, true), $data['file']);
        $this->logAction($request, 'cloud.google.file.uploaded');
        return response()->json(['data' => $result], 201);
    }

    public function download(Request $request, string $fileId, GoogleDriveService $drive)
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{5,200}$/D', $fileId), 422, 'Identificador inválido.');
        $data = $drive->download($this->account($request), $fileId);
        $this->logAction($request, 'cloud.google.file.downloaded');
        return response($data['body'], 200, [
            'Content-Type' => $data['mime'],
            'Content-Disposition' => 'attachment; filename="'.str_replace(['"', "\r", "\n"], '_', basename($data['name'])).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->account($request, true)->delete();
        $this->logAction($request, 'cloud.google.disconnected');
        return response()->json(['message' => 'Google Drive desconectado do ACE.']);
    }

    public function disconnectAccount(Request $request, string $accountId): JsonResponse
    {
        $this->permitted($request, true);
        abort_unless(ctype_digit($accountId), 422, 'Conta inválida.');
        GoogleDriveConnection::where('tenant_id', $this->tenant($request)->id)
            ->whereKey((int) $accountId)->firstOrFail()->delete();
        $this->logAction($request, 'cloud.google.disconnected');
        return response()->json(['message' => 'Conta desconectada do ACE.']);
    }
}
