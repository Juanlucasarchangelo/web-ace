<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_cannot_create_second_company_or_switch_legacy_membership(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $first = Tenant::create(['name'=>'Primeira', 'slug'=>'primeira', 'is_active'=>true, 'seat_limit'=>5]);
        $second = Tenant::create(['name'=>'Segunda', 'slug'=>'segunda', 'is_active'=>true, 'seat_limit'=>5]);
        $owner->tenants()->attach($first->id, ['role'=>'owner']);
        $owner->tenants()->attach($second->id, ['role'=>'owner']);
        $this->actingAs($owner);
        $this->postJson('/api/v1/workspaces', ['name'=>'Terceira'])->assertForbidden();
        $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeader('X-Tenant-Id', $second->id)->getJson('/api/v1/clients')->assertForbidden();
        $this->assertDatabaseCount('tenants', 2);
    }

    public function test_master_can_edit_legal_identity_but_not_plan(): void
    {
        $owner = User::factory()->create(['is_active'=>true]);
        $tenant = Tenant::create(['name'=>'Contratante', 'slug'=>'contratante', 'is_active'=>true, 'plan'=>'free', 'seat_limit'=>1]);
        $owner->tenants()->attach($tenant->id, ['role'=>'owner']);
        $this->actingAs($owner)->putJson('/api/v1/workspaces/current', [
            'name'=>'Contratante Atualizado', 'document_type'=>'CNPJ',
            'document_number'=>'11.222.333/0001-81', 'city'=>'São Paulo', 'state'=>'SP',
            'plan'=>'pro', 'seat_limit'=>999,
        ])->assertOk();
        $tenant->refresh();
        $this->assertSame('11222333000181', $tenant->document_number);
        $this->assertSame('free', $tenant->plan);
        $this->assertSame(1, $tenant->seat_limit);
    }
}
