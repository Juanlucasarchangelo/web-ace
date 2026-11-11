<?php

namespace App\Services;

use App\Models\DevOperationalIssue;
use App\Models\GoogleDriveConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** OAuth connectivity checks use only the token endpoint. No Drive file APIs are accessed. */
class GoogleDriveHealth
{
    private const MESSAGES = [
        'invalid_grant' => 'A autorização foi revogada ou expirou. Reconecte esta conta ao Google Drive.',
        'invalid_client' => 'O Google recusou as credenciais OAuth do aplicativo. Verifique a configuração no Google Cloud.',
        'access_denied' => 'A autorização do Google foi recusada ou cancelada pelo usuário.',
        'invalid_scope' => 'O Google recusou os escopos solicitados pelo aplicativo.',
        'missing_refresh_token' => 'Esta conexão não possui autorização de renovação; é necessário reconectar.',
        'rate_limited' => 'O Google limitou temporariamente as solicitações. Tente novamente mais tarde.',
        'google_unavailable' => 'O serviço do Google está temporariamente indisponível.',
        'network_error' => 'Não foi possível alcançar o serviço de autorização do Google.',
        'permission_denied' => 'O Google Drive recusou a operação por falta de permissão.',
        'drive_api_error' => 'O Google Drive recusou uma operação; verifique a conexão e as permissões.',
        'oauth_state_error' => 'A autorização expirou ou não corresponde à sessão que iniciou a conexão.',
        'oauth_profile_error' => 'O Google não retornou uma identidade válida para a conta conectada.',
        'oauth_token_error' => 'O Google não concluiu a autorização inicial da conta.',
        'internal_error' => 'O servidor encontrou uma falha inesperada em uma operação do workspace. Verifique os logs locais com acesso técnico.',
    ];

    public static function message(?string $code): string
    {
        return self::MESSAGES[$code ?? ''] ?? 'A conexão apresenta um problema que precisa de verificação.';
    }

    public function failure(string $tenantId, ?GoogleDriveConnection $connection, string $code, string $source = 'google_drive'): void
    {
        if (! isset(self::MESSAGES[$code])) $code = 'drive_api_error';
        if ($connection) {
            $connection->forceFill([
                'health_status' => in_array($code, ['invalid_grant', 'missing_refresh_token', 'access_denied'], true)
                    ? 'reconnect_required' : 'error',
                'last_error_code' => $code,
                'last_error_at' => now(),
                'last_checked_at' => now(),
            ])->save();
        }
        // Keep an append-only, metadata-only history; never persist Google response bodies, tokens or filenames.
        DevOperationalIssue::create([
            'tenant_id' => $tenantId, 'connection_id' => $connection?->id, 'source' => $source,
            'code' => $code, 'summary' => self::message($code),
            'severity' => in_array($code, ['invalid_grant', 'invalid_client', 'missing_refresh_token'], true) ? 'critical' : 'warning',
            'created_at' => now(),
        ]);
    }

    public function success(GoogleDriveConnection $connection): void
    {
        $connection->forceFill([
            'health_status' => 'connected', 'last_error_code' => null,
            'last_error_at' => null, 'last_checked_at' => now(), 'last_success_at' => now(),
        ])->save();
        DevOperationalIssue::query()->where('tenant_id', $connection->tenant_id)
            ->where('connection_id', $connection->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
    }

    public function codeFor(Response $response): string
    {
        $error = $response->json('error');
        if (is_string($error) && isset(self::MESSAGES[$error])) return $error;
        return match ($response->status()) {
            401 => 'invalid_grant', 403 => 'permission_denied', 429 => 'rate_limited',
            500, 502, 503, 504 => 'google_unavailable', default => 'drive_api_error',
        };
    }

    public function observeApi(GoogleDriveConnection $connection, Response $response): void
    {
        if ($response->successful()) {
            $this->success($connection);
        } else {
            $this->failure($connection->tenant_id, $connection, $this->codeFor($response));
        }
    }

    public function refresh(GoogleDriveConnection $connection): string
    {
        if (! $connection->refresh_token) {
            $this->failure($connection->tenant_id, $connection, 'missing_refresh_token');
            throw ValidationException::withMessages(['drive' => self::message('missing_refresh_token')]);
        }
        try {
            $response = Http::asForm()->timeout(18)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_drive.client_id'),
                'client_secret' => config('services.google_drive.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (\Throwable $e) {
            $this->failure($connection->tenant_id, $connection, 'network_error');
            throw ValidationException::withMessages(['drive' => self::message('network_error')]);
        }
        if (! $response->successful() || ! is_string($response->json('access_token')) || ! $response->json('access_token')) {
            $code = $this->codeFor($response);
            $this->failure($connection->tenant_id, $connection, $code);
            throw ValidationException::withMessages(['drive' => self::message($code)]);
        }
        $connection->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?: $connection->refresh_token,
            'expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
        ]);
        $this->success($connection);
        return $connection->access_token;
    }

    public function check(GoogleDriveConnection $connection): array
    {
        try { $this->refresh($connection); } catch (ValidationException $ignored) { /* Sanitized status persisted. */ }
        $connection->refresh();
        return [
            'id' => $connection->id,
            'status' => $connection->health_status,
            'error_code' => $connection->last_error_code,
            'message' => $connection->last_error_code ? self::message($connection->last_error_code) : 'Autorização validada com o Google.',
            'checked_at' => $connection->last_checked_at?->toIso8601String(),
        ];
    }
}
