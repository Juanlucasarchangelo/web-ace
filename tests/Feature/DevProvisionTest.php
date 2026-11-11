<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevProvisionTest extends TestCase
{
    use RefreshDatabase;

    private function dev(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['is_dev' => true])->save();
        return $user;
    }

    private function payload(): array
    {
        return [
            'company' => 'Oficina de Teste', 'owner_name' => 'Conta Master',
            'owner_email' => 'master@example.test', 'owner_password' => 'SenhaForte!2026XX',
            'plan' => 'free', 'seat_limit' => 3,
        ];
    }

    public function test_dev_creates_tenant_and_owner_without_smtp(): void
    {
        config()->set('mail.default', 'log');
        $this->actingAs($this->dev())
            ->postJson('/api/v1/dev/provision/tenants', $this->payload())
            ->assertCreated()->assertJsonPath('data.owner_email', 'master@example.test');
        $master = User::where('email', 'master@example.test')->firstOrFail();
        $tenant = $master->tenants()->firstOrFail();
        $this->assertSame('owner', $tenant->pivot->role);
        $this->assertSame('manual', $tenant->billing_provider);
        $this->assertSame('unverified', $tenant->billing_status);
        $this->assertTrue(Hash::check('SenhaForte!2026XX', $master->password));
        $this->assertNull($master->email_verified_at);
        $this->assertDatabaseHas('dev_admin_audits', ['action' => 'dev.tenant.provisioned']);
    }

    public function test_regular_user_cannot_provision_a_tenant(): void
    {
        $this->actingAs(User::factory()->create(['is_active' => true]))
            ->postJson('/api/v1/dev/provision/tenants', $this->payload())
            ->assertForbidden();
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_dev_adds_user_and_enforces_seat_limit(): void
    {
        $dev = $this->dev();
        $r = $this->actingAs($dev)->postJson('/api/v1/dev/provision/tenants', $this->payload())->assertCreated();
        $id = $r->json('data.id');
        $member = ['tenant_id' => $id, 'name' => 'Pessoa Interna',
            'email' => 'nova@example.test', 'password' => 'SenhaForte!2026XX', 'role' => 'member'];
        $this->postJson('/api/v1/dev/provision/users', $member)->assertCreated();
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $id, 'role' => 'member']);
        $member['email'] = 'outra@example.test';
        $this->postJson('/api/v1/dev/provision/users', $member)->assertCreated();
        $member['email'] = 'excedente@example.test';
        $this->postJson('/api/v1/dev/provision/users', $member)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'excedente@example.test']);
    }

    public function test_dev_cannot_attach_a_dev_identity_as_normal_member(): void
    {
        $dev = $this->dev();
        $r = $this->actingAs($dev)->postJson('/api/v1/dev/provision/tenants', $this->payload())->assertCreated();
        $this->postJson('/api/v1/dev/provision/users', [
            'tenant_id' => $r->json('data.id'), 'name' => 'Conta DEV',
            'email' => $dev->email, 'password' => 'SenhaForte!2026XX', 'role' => 'admin',
        ])->assertUnprocessable();
    }
}
