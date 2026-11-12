<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamAndProfileAccessTest extends TestCase
{
    use RefreshDatabase;

    private function team(): array
    {
        $tenant = Tenant::create(['name' => 'Contrato ACE', 'slug' => 'contrato-ace',
            'plan' => 'free', 'seat_limit' => 5, 'is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['is_active' => true]);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);
        $tenant->users()->attach($admin->id, ['role' => 'admin']);
        $tenant->users()->attach($member->id, ['role' => 'member']);
        return [$tenant, $owner, $admin, $member];
    }

    public function test_only_master_and_admin_manage_team(): void
    {
        [, $owner, $admin, $member] = $this->team();
        $this->actingAs($member)->getJson('/api/v1/team')->assertForbidden();
        $this->actingAs($member)->postJson('/api/v1/team', [])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/team')->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/team')->assertOk();
    }

    public function test_admin_can_manage_member_but_cannot_remove_master(): void
    {
        [, $owner, $admin, $member] = $this->team();
        $this->actingAs($admin)
            ->patchJson('/api/v1/team/'.$member->id.'/status', ['is_active' => false])->assertOk();
        $this->actingAs($member)->getJson('/api/v1/dashboard')->assertForbidden();
        $this->actingAs($admin)
            ->deleteJson('/api/v1/team/'.$owner->id)->assertForbidden();
        $this->actingAs($admin)
            ->patchJson('/api/v1/team/'.$member->id.'/status', ['is_active' => true])->assertOk();
        $this->actingAs($member)->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_team_password_reset_does_not_delete_user_or_tenant_records(): void
    {
        [$tenant, , $admin, $member] = $this->team();
        $this->actingAs($admin)->putJson('/api/v1/team/'.$member->id.'/password', [
            'password' => 'NovaSenhaDeTeste!2026', 'password_confirmation' => 'NovaSenhaDeTeste!2026',
        ])->assertOk();
        $this->assertTrue(Hash::check('NovaSenhaDeTeste!2026', $member->fresh()->password));
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $member->id]);
    }

    public function test_member_can_create_client_but_cannot_change_existing_client(): void
    {
        [$tenant, , , $member] = $this->team();
        $this->actingAs($member)->postJson('/api/v1/clients', [
            'name' => 'Cliente autorizado', 'person_type' => 'company', 'status' => 'active',
        ])->assertCreated();
        $client = Client::where('tenant_id', $tenant->id)->firstOrFail();
        $this->actingAs($member)->putJson('/api/v1/clients/'.$client->id, [
            'name' => 'Alteração não autorizada', 'person_type' => 'company', 'status' => 'active',
        ])->assertForbidden();
    }

    public function test_profile_can_be_changed_by_member_without_team_permissions(): void
    {
        [, , , $member] = $this->team();
        $this->actingAs($member)->putJson('/api/v1/auth/profile', [
            'name' => 'Colaborador Atualizado', 'email' => $member->email,
            'phone' => '11999999999', 'job_title' => 'Consultor',
        ])->assertOk();
        $this->assertSame('Colaborador Atualizado', $member->fresh()->name);
        $this->actingAs($member)->getJson('/api/v1/auth/profile')->assertJsonPath('data.job_title', 'Consultor');
    }
}
