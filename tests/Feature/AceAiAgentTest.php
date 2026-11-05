<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AceAi\GeminiConversation;
use App\Services\AceAi\SecureIntakeParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AceAiAgentTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = "Cliente Exemplo\ncliente-exemplo.com.br\nUsuário: usuario@cliente-exemplo.com.br\nSenha: TESTE-NAO-REAL\nLink de acesso: webmail.cliente-exemplo.com.br\nRua Exemplo, 234 - Osasco\n11222333000181";

    private function member(string $role = 'owner', string $slug = 'empresa-test'): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'is_active' => true, 'plan' => 'pro']);
        $user->tenants()->attach($tenant->id, ['role' => $role]);
        return [$user, $tenant];
    }

    public function test_parser_extracts_credentials_locally_and_normalizes_webmail_url(): void
    {
        $parser = app(SecureIntakeParser::class);
        $this->assertTrue($parser->looksLikeIntake(self::SAMPLE));
        $data = $parser->parse(self::SAMPLE);
        $this->assertSame([], $parser->errors($data));
        $this->assertSame('Cliente Exemplo', $data['client']['name']);
        $this->assertSame('company', $data['client']['person_type']);
        $this->assertSame('11222333000181', $data['client']['document']);
        $this->assertSame('Osasco', $data['client']['address_city']);
        $this->assertSame('cliente-exemplo.com.br', $data['site']['domain']);
        $this->assertSame('https://webmail.cliente-exemplo.com.br', $data['access']['url']);
    }

    public function test_intake_creates_client_site_access_only_after_confirmation_and_never_echoes_secret(): void
    {
        [$user, $tenant] = $this->member();
        config()->set('ace_ai.provider', 'gemini');
        $model = Mockery::mock(GeminiConversation::class);
        $model->shouldNotReceive('respond');
        $this->app->instance(GeminiConversation::class, $model);

        $draft = $this->actingAs($user)->postJson('/api/v1/ai/chat', [
            'message' => self::SAMPLE,
            'context' => ['page' => 'clientes'],
        ])->assertOk()->assertJsonPath('data.intent', 'secure_intake')
            ->assertDontSee('TESTE-NAO-REAL')
            ->assertDontSee('11222333000181');
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_accesses', 0);
        $token = $draft->json('data.confirmation.token');
        $this->assertNotEmpty($token);
        $this->postJson('/api/v1/ai/confirm', ['token' => $token])->assertOk()->assertDontSee('TESTE-NAO-REAL');
        $this->postJson('/api/v1/ai/confirm', ['token' => $token])->assertUnprocessable();
        $client = Client::where('tenant_id', $tenant->id)->firstOrFail();
        $site = Site::where('tenant_id', $tenant->id)->firstOrFail();
        $access = SiteAccess::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($client->id, $site->client_id);
        $this->assertSame($site->id, $access->site_id);
        $this->assertSame('TESTE-NAO-REAL', $access->secret);
        $this->assertNotSame('TESTE-NAO-REAL', $access->getRawOriginal('secret'));
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'action' => 'ace_ai.access.created']);
    }

    public function test_viewer_cannot_prepare_sensitive_registration(): void
    {
        [$user] = $this->member('viewer');
        $this->actingAs($user)->postJson('/api/v1/ai/chat', ['message' => self::SAMPLE])->assertForbidden();
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_confirmation_cannot_be_used_by_another_workspace(): void
    {
        [$userA] = $this->member('owner', 'acme-a');
        [$userB] = $this->member('owner', 'acme-b');
        $draft = $this->actingAs($userA)->postJson('/api/v1/ai/chat', ['message' => self::SAMPLE])->assertOk();
        $token = $draft->json('data.confirmation.token');
        $this->actingAs($userB)->postJson('/api/v1/ai/confirm', ['token' => $token])->assertForbidden();
        $this->assertDatabaseCount('clients', 0);
        $this->actingAs($userA)->postJson('/api/v1/ai/confirm', ['token' => $token])->assertOk();
    }

    public function test_page_help_is_from_allowlisted_page_description_without_model_call(): void
    {
        [$user] = $this->member();
        $model = Mockery::mock(GeminiConversation::class);
        $model->shouldNotReceive('respond');
        $this->app->instance(GeminiConversation::class, $model);
        $this->actingAs($user)->postJson('/api/v1/ai/chat', [
            'message' => 'Me explique esta tela e o que posso fazer aqui.',
            'context' => ['page' => 'nuvem'],
        ])->assertOk()->assertJsonPath('data.intent', 'page_help')->assertSee('Google Drive');
    }
}
