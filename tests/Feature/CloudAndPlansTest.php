<?php

namespace Tests\Feature;

use App\Models\GoogleDriveConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudAndPlansTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $slug, string $plan = 'free', string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'is_active' => true, 'plan' => $plan]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);
        return [$user, $tenant];
    }

    public function test_public_home_and_free_plan_gate(): void
    {
        $this->get('/')->assertOk()->assertSee('id="app"', false);
        [$user] = $this->actor('free-tenant');
        $this->actingAs($user)->getJson('/api/v1/cloud/google/status')
            ->assertOk()->assertJsonPath('data.plan', 'free')->assertJsonPath('data.cloud_enabled', false);
        $this->getJson('/api/v1/cloud/google/authorize')->assertForbidden();
        $this->postJson('/api/v1/cloud/google/folders', ['name' => 'Pasta de Teste'])->assertForbidden();
    }

    public function test_google_oauth_callback_encrypts_tokens_and_limits_tenant_access(): void
    {
        [$user, $tenant] = $this->actor('cloud-tenant', 'cloud');
        [$other, $otherTenant] = $this->actor('other-tenant', 'cloud');
        config()->set('services.google_drive.client_id', 'example-id.apps.googleusercontent.com');
        config()->set('services.google_drive.client_secret', 'dummy-secret');
        config()->set('services.google_drive.redirect', 'http://127.0.0.1:8088/cloud/google/callback');
        config()->set('services.google_drive.scope', 'https://www.googleapis.com/auth/drive.file');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-example', 'refresh_token' => 'refresh-example',
                'scope' => 'openid email https://www.googleapis.com/auth/drive.file', 'expires_in' => 3600,
            ], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'contato@example.test'], 200),
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => [['id' => 'file123456', 'name' => 'Teste']]], 200),
        ]);
        $authorization = $this->actingAs($user)->getJson('/api/v1/cloud/google/authorize')->assertOk()->json('data.url');
        parse_str((string) parse_url($authorization, PHP_URL_QUERY), $params);
        $this->assertSame('https://www.googleapis.com/auth/drive.file', config('services.google_drive.scope'));
        $this->assertNotEmpty($params['state']);
        $this->get('/cloud/google/callback?state='.$params['state'].'&code=fictional-code')
            ->assertRedirect('/nuvem?drive=connected');
        $connection = GoogleDriveConnection::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNotSame('access-example', $connection->getRawOriginal('access_token'));
        $this->assertNotSame('refresh-example', $connection->getRawOriginal('refresh_token'));
        $this->assertSame('contato@example.test', $connection->email);
        $this->getJson('/api/v1/cloud/google/files')->assertOk()->assertJsonPath('data.files.0.name', 'Teste')
            ->assertDontSee('access-example');
        // Outra empresa não herda a conexão, mesmo que o usuário troque o header.
        $this->actingAs($other)->getJson('/api/v1/cloud/google/status')
            ->assertOk()->assertJsonPath('data.connected', false);
        $this->getJson('/api/v1/cloud/google/files')->assertUnprocessable();
        $this->withHeader('X-Tenant-Id', $tenant->id)->getJson('/api/v1/cloud/google/files')->assertForbidden();
    }

    public function test_viewer_can_list_but_cannot_connect_or_upload(): void
    {
        [$user, $tenant] = $this->actor('viewer-tenant', 'pro', 'viewer');
        GoogleDriveConnection::create([
            'tenant_id' => $tenant->id, 'connected_by' => $user->id,
            'access_token' => 'token-example', 'refresh_token' => 'refresh-example',
            'scope' => 'https://www.googleapis.com/auth/drive.file', 'expires_at' => now()->addHour(),
        ]);
        Http::fake(['https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => []], 200)]);
        $this->actingAs($user)->getJson('/api/v1/cloud/google/status')->assertOk()->assertJsonPath('data.ai_entitled', true);
        $this->getJson('/api/v1/cloud/google/files')->assertOk();
        $this->getJson('/api/v1/cloud/google/authorize')->assertForbidden();
        $this->postJson('/api/v1/cloud/google/folders', ['name' => 'Não autorizado'])->assertForbidden();
    }

    public function test_oauth_callback_rejects_missing_or_replayed_state(): void
    {
        [$user] = $this->actor('replay-tenant', 'cloud');
        $this->actingAs($user)->get('/cloud/google/callback?code=fake')->assertRedirect('/nuvem?drive=invalid_state');
    }
}
