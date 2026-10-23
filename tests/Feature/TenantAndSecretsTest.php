<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Site;
use App\Models\SiteAccess;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAndSecretsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $slug): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        return [$user, $tenant];
    }

    private function site(Tenant $tenant): Site
    {
        $client = Client::create(['tenant_id' => $tenant->id, 'name' => 'Cliente de Teste', 'person_type' => 'company', 'status' => 'active']);
        return Site::create(['tenant_id' => $tenant->id, 'client_id' => $client->id, 'name' => 'Site teste', 'domain' => $tenant->slug.'.example.test', 'status' => 'active']);
    }

    public function test_tenant_cannot_access_another_tenant_site_or_secret(): void
    {
        [$alice, $tenantA] = $this->account('empresa-a');
        [, $tenantB] = $this->account('empresa-b');
        $site = $this->site($tenantB);
        $access = SiteAccess::create(['tenant_id' => $tenantB->id, 'site_id' => $site->id, 'category' => 'ftp', 'label' => 'Acesso fictício', 'secret' => 'FICTICIO-SEGREDO']);
        $this->actingAs($alice)
            ->getJson('/api/v1/sites/'.$site->id)->assertNotFound();
        $this->postJson('/api/v1/sites/'.$site->id.'/accesses/'.$access->id.'/reveal')->assertNotFound();
        $this->withHeader('X-Tenant-Id', $tenantB->id)->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_secret_stored_encrypted_not_returned_in_json_but_reveal_is_audited(): void
    {
        [$user, $tenant] = $this->account('empresa-c');
        $site = $this->site($tenant);
        $access = SiteAccess::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'category' => 'ftp', 'label' => 'Teste FTP', 'secret' => 'FICTICIO-SEGREDO']);
        $this->assertNotSame('FICTICIO-SEGREDO', $access->getRawOriginal('secret'));
        $this->actingAs($user)->getJson('/api/v1/sites/'.$site->id)
            ->assertOk()->assertDontSee('FICTICIO-SEGREDO');
        $this->getJson('/api/v1/vault')->assertOk()->assertDontSee('FICTICIO-SEGREDO');
        $this->postJson('/api/v1/sites/'.$site->id.'/accesses/'.$access->id.'/reveal')
            ->assertOk()->assertJsonPath('data.secret', 'FICTICIO-SEGREDO');
        $this->assertDatabaseHas('audit_events', ['tenant_id' => $tenant->id, 'user_id' => $user->id, 'action' => 'access.secret.revealed']);
    }

    public function test_viewer_cannot_reveal_or_modify(): void
    {
        [$user, $tenant] = $this->account('empresa-d');
        $tenant->users()->updateExistingPivot($user->id, ['role' => 'viewer']);
        $site = $this->site($tenant);
        $access = SiteAccess::create(['tenant_id' => $tenant->id, 'site_id' => $site->id, 'category' => 'other', 'label' => 'Teste', 'secret' => 'FICTICIO']);
        $this->actingAs($user)
            ->postJson('/api/v1/sites/'.$site->id.'/accesses/'.$access->id.'/reveal')->assertForbidden();
        $this->deleteJson('/api/v1/sites/'.$site->id)->assertForbidden();
    }
}
