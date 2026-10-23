<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private function bootstrapAccount(): array
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'Ficticia@2026ACE', 'is_active' => true]);
        $tenant = Tenant::create(['name' => 'Empresa Teste', 'slug' => 'empresa-teste', 'is_active' => true]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        return [$user, $tenant];
    }

    public function test_private_api_requires_authentication(): void
    {
        foreach (['/api/v1/auth/me', '/api/v1/dashboard', '/api/v1/clients', '/api/v1/sites', '/api/v1/vault', '/api/v1/workspaces', '/api/v1/team', '/api/v1/activity'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_login_session_then_me_and_logout(): void
    {
        [$user, $tenant] = $this->bootstrapAccount();
        $this->withHeaders(['Referer' => 'http://127.0.0.1:8088/', 'Origin' => 'http://127.0.0.1:8088'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Ficticia@2026ACE'])
            ->assertOk()->assertJsonPath('data.tenant.id', $tenant->id);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_bad_login_does_not_authenticate(): void
    {
        $this->bootstrapAccount();
        $this->withHeaders(['Referer' => 'http://127.0.0.1:8088/'])
            ->postJson('/api/v1/auth/login', ['email' => 'owner@example.test', 'password' => 'incorreta'])
            ->assertUnprocessable();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
