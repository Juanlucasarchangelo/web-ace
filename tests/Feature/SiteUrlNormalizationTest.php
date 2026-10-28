<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteUrlNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => 'Empresa URL',
            'slug' => 'empresa-url',
            'is_active' => true,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $client = Client::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente Teste',
            'person_type' => 'company',
            'status' => 'active',
        ]);
        $this->actingAs($user);

        return [$tenant, $client];
    }

    private function sitePayload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'name' => 'Archania Boutique',
            'domain' => 'archania.com.br',
            'status' => 'active',
            'control_panel_url' => 'painel.archania.com.br:8080',
            'store_url' => 'archania.com.br',
            'social_links' => [
                'drive' => 'drive.google.com/drive/u/0/my-drive',
                'youtube' => 'https://youtube.com/@archania',
                'facebook' => 'facebook.com/archania',
                'linkedin' => 'linkedin.com/company/archania',
                'linktree' => 'linktr.ee/archania',
                'instagram' => '@archania',
                'gmail' => 'contato@archania.com.br',
            ],
        ];
    }

    public function test_create_site_accepts_all_links_without_scheme(): void
    {
        [, $client] = $this->setupTenant();
        $response = $this->postJson('/api/v1/sites', $this->sitePayload($client));
        $response->assertCreated();

        $site = Site::findOrFail($response->json('data.id'));
        $this->assertSame('https://archania.com.br', $site->store_url);
        $this->assertSame('https://painel.archania.com.br:8080', $site->control_panel_url);
        $this->assertSame('https://drive.google.com/drive/u/0/my-drive', $site->social_links['drive']);
        $this->assertSame('https://youtube.com/@archania', $site->social_links['youtube']);
        $this->assertSame('https://facebook.com/archania', $site->social_links['facebook']);
        $this->assertSame('https://linkedin.com/company/archania', $site->social_links['linkedin']);
        $this->assertSame('https://linktr.ee/archania', $site->social_links['linktree']);
        $this->assertSame('@archania', $site->social_links['instagram']);
    }

    public function test_update_site_normalizes_links_and_preserves_other_fields(): void
    {
        [$tenant, $client] = $this->setupTenant();
        $site = Site::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'name' => 'Archania Boutique',
            'domain' => 'archania.com.br',
            'status' => 'active',
        ]);
        $payload = $this->sitePayload($client);
        $payload['store_url'] = '  loja.archania.com.br  ';
        $payload['social_links']['facebook'] = '';

        $this->putJson('/api/v1/sites/'.$site->id, $payload)->assertOk();
        $site->refresh();
        $this->assertSame('https://loja.archania.com.br', $site->store_url);
        $this->assertNull($site->social_links['facebook']);
        $this->assertSame('contato@archania.com.br', $site->social_links['gmail']);
    }

    public function test_access_create_and_update_accept_scheme_less_url(): void
    {
        [$tenant, $client] = $this->setupTenant();
        $site = Site::create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'name' => 'Archania Boutique',
            'domain' => 'archania.com.br',
            'status' => 'active',
        ]);
        $response = $this->postJson('/api/v1/sites/'.$site->id.'/accesses', [
            'category' => 'email',
            'label' => 'Contato',
            'url' => 'webmail.archania.com.br',
        ]);
        $response->assertCreated()->assertJsonPath('data.url', 'https://webmail.archania.com.br');
        $accessId = $response->json('data.id');

        $this->putJson('/api/v1/sites/'.$site->id.'/accesses/'.$accessId, [
            'category' => 'email',
            'label' => 'Contato',
            'url' => 'http://webmail.archania.com.br',
        ])->assertOk()->assertJsonPath('data.url', 'http://webmail.archania.com.br');
    }

    public function test_invalid_urls_and_unsafe_schemes_are_still_rejected(): void
    {
        [, $client] = $this->setupTenant();
        $payload = $this->sitePayload($client);
        $payload['store_url'] = 'javascript:alert(1)';
        $payload['social_links']['drive'] = 'endereco com espacos';
        $this->postJson('/api/v1/sites', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store_url', 'social_links.drive']);
    }
}
