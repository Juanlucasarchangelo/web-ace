<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoogleDriveConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AceAi\GeminiConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AceAiPremiumTest extends TestCase
{
    use RefreshDatabase;

    private function membership(string $role = 'owner', string $slug = 'empresa-a'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'is_active' => true, 'plan' => 'pro']);
        $user->tenants()->attach($tenant->id, ['role' => $role]);
        return [$user, $tenant];
    }

    private function useGeminiDecision(array $decision): void
    {
        $model = Mockery::mock(GeminiConversation::class);
        $model->shouldReceive('sensitive')->andReturn(false);
        $model->shouldReceive('respond')->andReturn(array_merge([
            'intent' => 'chat', 'query' => '', 'reply' => '', 'presentation' => 'text',
            'client_name' => '', 'site_name' => '', 'domain' => '',
            'engine' => 'gemini', 'model' => 'gemini-3.1-pro-preview',
        ], $decision));
        $this->app->instance(GeminiConversation::class, $model);
    }

    public function test_clients_are_counted_in_current_workspace_without_cards(): void
    {
        [$user, $tenant] = $this->membership();
        [, $other] = $this->membership('owner', 'empresa-b');
        Client::create(['tenant_id' => $tenant->id, 'name' => 'Cliente A', 'person_type' => 'company', 'status' => 'active']);
        Client::create(['tenant_id' => $other->id, 'name' => 'Cliente B', 'person_type' => 'company', 'status' => 'active']);
        $this->useGeminiDecision(['intent' => 'clients', 'presentation' => 'cards']);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Quantos clientes eu tenho? Não quero cards'])
            ->assertOk()->assertJsonPath('data.clients', [])->assertSee('1 cliente(s)');
    }

    public function test_dns_reads_allowlisted_fields_and_never_secrets(): void
    {
        [$user, $tenant] = $this->membership();
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'Archania', 'person_type' => 'company', 'status' => 'active']);
        Site::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'name' => 'Archania',
            'domain' => 'archania.com.br', 'status' => 'active',
            'dns_primary' => 'ns1.example.test', 'registro_br' => 'SEGREDO_DE_TESTE']);
        $this->useGeminiDecision(['intent' => 'site_details', 'query' => 'archania.com.br']);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Qual o DNS do site archania.com.br?'])
            ->assertOk()->assertSee('ns1.example.test')->assertDontSee('SEGREDO_DE_TESTE');
    }

    public function test_a_write_requires_explicit_confirmation_and_is_audited(): void
    {
        [$user, $tenant] = $this->membership();
        $this->useGeminiDecision(['intent' => 'create_client', 'client_name' => 'Cliente Novo']);
        $response = $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Cadastre o cliente Cliente Novo'])
            ->assertOk()->assertJsonPath('data.intent', 'create_client');
        $this->assertDatabaseMissing('clients', ['tenant_id' => $tenant->id, 'name' => 'Cliente Novo']);
        $token = $response->json('data.confirmation.token');
        $this->assertNotEmpty($token);
        $this->postJson('/api/v1/ai/confirm', ['token' => $token])->assertOk();
        $this->assertDatabaseHas('clients', ['tenant_id' => $tenant->id, 'name' => 'Cliente Novo']);
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'ace_ai.client.created']);
        $this->postJson('/api/v1/ai/confirm', ['token' => $token])->assertUnprocessable();
    }

    public function test_viewer_cannot_create_clients_through_ai(): void
    {
        [$user, $tenant] = $this->membership('viewer');
        $this->useGeminiDecision(['intent' => 'create_client', 'client_name' => 'Cliente Bloqueado']);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Cadastre o cliente Cliente Bloqueado'])
            ->assertForbidden();
        $this->assertDatabaseMissing('clients', ['tenant_id' => $tenant->id, 'name' => 'Cliente Bloqueado']);
    }

    public function test_drive_status_does_not_return_tokens(): void
    {
        [$user, $tenant] = $this->membership();
        GoogleDriveConnection::create([
            'tenant_id' => $tenant->id,
            'connected_by' => $user->id,
            'email' => 'drive@example.test',
            'access_token' => 'ACCESS_TOKEN_SECRET_TEST',
            'refresh_token' => 'REFRESH_TOKEN_SECRET_TEST',
            'scope' => 'drive.file',
            'expires_at' => now()->addHour(),
        ]);
        $this->useGeminiDecision(['intent' => 'drive_status']);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => 'Quantas contas do Google Drive estão conectadas?'])
            ->assertOk()->assertSee('drive@example.test')
            ->assertDontSee('ACCESS_TOKEN_SECRET_TEST')->assertDontSee('REFRESH_TOKEN_SECRET_TEST');
    }
}
