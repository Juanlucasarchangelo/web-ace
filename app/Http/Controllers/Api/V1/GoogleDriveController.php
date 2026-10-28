<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditEvent;
use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveService;
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
    private function allowed(Request $request, bool $write = false): ?GoogleDriveConnection
    {
        abort_unless(
            PlanFeatures::cloud($this->tenant($request)),
            403,
            'Google Drive requer o plano Cloud ou Pro.'
        );

        if ($write) {
            $this->canWrite($request);
        }

        return GoogleDriveConnection::where(
            'tenant_id',
            $this->tenant($request)->id
        )->first();
    }

    private function connected(Request $request, bool $write = false): GoogleDriveConnection
    {
        return $this->allowed($request, $write)
            ?? throw ValidationException::withMessages([
                'drive' => 'Conecte o Google Drive antes de continuar.',
            ]);
    }

    public function status(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $connection = PlanFeatures::cloud($tenant)
            ? $this->allowed($request)
            : null;

        return response()->json(['data' => [
            'plan' => $tenant->plan ?? 'free',
            'cloud_enabled' => PlanFeatures::cloud($tenant),
            'ai_entitled' => PlanFeatures::ai($tenant),
            'connected' => (bool) $connection,
            'can_manage' => in_array($this->role($request), ['owner', 'admin'], true),
            'email' => $connection?->email,
            'scope' => $connection?->scope,
            'configured' => (bool) (
                config('services.google_drive.client_id')
                && config('services.google_drive.client_secret')
            ),
        ]]);
    }

    public function authorize(Request $request): JsonResponse
    {
        $this->allowed($request, true);

        abort_unless(
            config('services.google_drive.client_id')
                && config('services.google_drive.client_secret'),
            503,
            'Google Drive não configurado pelo administrador do ACE.'
        );

        $state = bin2hex(random_bytes(32));

        Cache::put(
            'ace_drive_oauth:' . hash('sha256', $state),
            [
                'user_id' => $request->user()->id,
                'tenant_id' => $this->tenant($request)->id,
            ],
            now()->addMinutes(10)
        );

        $params = http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => config('services.google_drive.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email ' . config('services.google_drive.scope'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);

        return response()->json([
            'data' => [
                'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . $params,
            ],
        ]);
    }

    public function callback(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            Log::warning('ACE Google OAuth: sessão ausente no callback.');
            return redirect('/nuvem?drive=invalid_state');
        }

        $state = (string) $request->query('state', '');

        $pending = $state
            ? Cache::pull('ace_drive_oauth:' . hash('sha256', $state))
            : null;

        if (
            ! is_array($pending)
            || (string) ($pending['user_id'] ?? '') !== (string) $user->id
        ) {
            Log::warning('ACE Google OAuth: state inválido ou expirado.');
            return redirect('/nuvem?drive=invalid_state');
        }

        $tenant = $user->tenants()
            ->where('tenants.id', $pending['tenant_id'])
            ->where('tenants.is_active', true)
            ->first();

        if (
            ! $tenant
            || ! in_array($tenant->pivot->role, ['owner', 'admin'], true)
            || ! PlanFeatures::cloud($tenant)
        ) {
            Log::warning('ACE Google OAuth: acesso não autorizado.');
            return redirect('/nuvem?drive=denied');
        }

        if ($request->query('error') || ! $request->filled('code')) {
            Log::warning('ACE Google OAuth: autorização cancelada ou recusada.', [
                'google_error' => Str::limit(
                    (string) $request->query('error', 'missing_code'),
                    100
                ),
            ]);

            return redirect('/nuvem?drive=cancelled');
        }

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('services.google_drive.client_id'),
                    'client_secret' => config('services.google_drive.client_secret'),
                    'redirect_uri' => config('services.google_drive.redirect'),
                    'grant_type' => 'authorization_code',
                    'code' => $request->query('code'),
                ]);
        } catch (\Throwable $exception) {
            Log::error('ACE Google OAuth: falha de comunicação com Google.', [
                'exception_type' => get_class($exception),
            ]);

            return redirect('/nuvem?drive=token_error');
        }

        if (! $response->successful()) {
            Log::error('ACE Google OAuth: Google rejeitou troca de token.', [
                'http_status' => $response->status(),
                'google_error' => Str::limit(
                    (string) $response->json('error', 'unknown'),
                    100
                ),
            ]);

            return redirect('/nuvem?drive=token_error');
        }

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');

        if (! is_string($accessToken) || $accessToken === '') {
            Log::error('ACE Google OAuth: access_token ausente.', [
                'http_status' => $response->status(),
            ]);

            return redirect('/nuvem?drive=token_error');
        }

        $existingConnection = GoogleDriveConnection::where(
            'tenant_id',
            $tenant->id
        )->first();

        if (
            (! is_string($refreshToken) || $refreshToken === '')
            && $existingConnection
        ) {
            $refreshToken = $existingConnection->refresh_token;
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            Log::warning('ACE Google OAuth: refresh_token ausente.', [
                'http_status' => $response->status(),
            ]);

            return redirect('/nuvem?drive=token_error');
        }

        try {
            $profileResponse = Http::withToken($accessToken)
                ->timeout(10)
                ->get('https://openidconnect.googleapis.com/v1/userinfo');

            $email = $profileResponse->successful()
                ? $profileResponse->json('email')
                : null;
        } catch (\Throwable $exception) {
            Log::warning('ACE Google OAuth: não foi possível consultar o perfil.', [
                'exception_type' => get_class($exception),
            ]);

            $email = null;
        }

        GoogleDriveConnection::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'connected_by' => $user->id,
                'email' => $email,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => now()->addSeconds(
                    max(60, (int) $response->json('expires_in', 3600))
                ),
                'scope' => (string) $response->json('scope', ''),
            ]
        );

        AuditEvent::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'cloud.google.connected',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit(
                (string) $request->userAgent(),
                512,
                ''
            ),
            'created_at' => now(),
        ]);

        return redirect('/nuvem?drive=connected');
    }

    public function files(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $data = $request->validate([
            'page_token' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $drive->listFiles(
                $this->connected($request),
                $data['page_token'] ?? null
            ),
        ]);
    }

    public function folder(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:180'],
        ]);

        $result = $drive->folder(
            $this->connected($request, true),
            $data['name']
        );

        $this->logAction($request, 'cloud.google.folder.created');

        return response()->json(['data' => $result], 201);
    }

    public function upload(Request $request, GoogleDriveService $drive): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $result = $drive->upload(
            $this->connected($request, true),
            $data['file']
        );

        $this->logAction($request, 'cloud.google.file.uploaded');

        return response()->json(['data' => $result], 201);
    }

    public function download(Request $request, string $fileId, GoogleDriveService $drive)
    {
        abort_unless(
            preg_match('/^[a-zA-Z0-9_-]{5,200}$/', $fileId),
            422,
            'Identificador inválido.'
        );

        $data = $drive->download(
            $this->connected($request),
            $fileId
        );

        $this->logAction($request, 'cloud.google.file.downloaded');

        return response($data['body'], 200, [
            'Content-Type' => $data['mime'],
            'Content-Disposition' => 'attachment; filename="' .
                str_replace(
                    ['"', "\r", "\n"],
                    '_',
                    basename($data['name'])
                ) . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $connection = $this->connected($request, true);

        $connection->delete();

        $this->logAction($request, 'cloud.google.disconnected');

        return response()->json([
            'message' => 'Google Drive desconectado do ACE.',
        ]);
    }
}
