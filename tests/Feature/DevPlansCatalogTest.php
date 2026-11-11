<?php

namespace Tests\Feature;

use App\Models\AceBillingOrder;
use App\Models\User;
use App\Support\BillingPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevPlansCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function dev(): User
    {
        $dev = User::factory()->create();
        $dev->forceFill(['is_dev' => true])->save();
        return $dev;
    }

    public function test_only_dev_can_edit_plan_and_public_price_updates(): void
    {
        $ordinary = User::factory()->create();
        $path = '/api/v1/dev/plans/cloud';
        $payload = [
            'title' => 'Cloud Business', 'description' => 'Armazenamento conectado.',
            'features' => ['Google Drive', 'Clientes e sites'],
            'monthly_cents' => 13500, 'annual_cents' => 140400, 'is_active' => true,
            'confirm' => 'ALTERAR PLANO',
        ];
        $this->actingAs($ordinary)->patchJson($path, $payload)->assertForbidden();
        $this->actingAs($this->dev())->patchJson($path, $payload)->assertOk();
        $this->assertSame(13500, BillingPlans::price('cloud', 'monthly'));
        $this->assertSame(140400, BillingPlans::price('cloud', 'annual'));
        $this->getJson('/api/v1/billing/plans')->assertOk()
            ->assertJsonPath('data.plans.1.name', 'Cloud Business')
            ->assertJsonPath('data.plans.1.monthly_cents', 13500);
        $this->assertDatabaseHas('dev_admin_audits', ['action' => 'dev.plan.updated.cloud']);
    }

    public function test_free_plan_cannot_be_charged_or_disabled_and_requires_confirmation(): void
    {
        $this->actingAs($this->dev());
        $payload = [
            'title' => 'Free', 'description' => 'Plano gratuito.', 'features' => ['Clientes'],
            'monthly_cents' => 100, 'annual_cents' => 0, 'is_active' => false,
            'confirm' => 'ALTERAR PLANO',
        ];
        $this->patchJson('/api/v1/dev/plans/free', $payload)->assertUnprocessable();
        $payload['monthly_cents'] = 0;
        $payload['is_active'] = true;
        unset($payload['confirm']);
        $this->patchJson('/api/v1/dev/plans/free', $payload)->assertUnprocessable();
        $this->assertSame(0, BillingPlans::price('free', 'annual'));
    }

    public function test_existing_order_price_is_a_snapshot_even_after_catalog_edit(): void
    {
        $order = new AceBillingOrder(['plan' => 'pro', 'cycle' => 'monthly', 'amount_cents' => BillingPlans::price('pro', 'monthly')]);
        \App\Models\AcePlan::findOrFail('pro')->update(['monthly_cents' => 20000]);
        $this->assertSame(15900, $order->amount_cents);
        $this->assertSame(20000, BillingPlans::price('pro', 'monthly'));
    }

    public function test_expired_workspace_has_only_read_only_billing_assistant(): void
    {
        $user = User::factory()->create();
        $tenant = \App\Models\Tenant::create([
            'name' => 'Empresa', 'slug' => 'empresa-teste', 'is_active' => true,
            'plan' => 'pro', 'billing_provider' => 'mercadopago', 'billing_status' => 'paid',
            'billing_expires_at' => now()->subDay(),
        ]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);
        $headers = ['X-Tenant-Id' => $tenant->id];
        $this->actingAs($user)->getJson('/api/v1/billing/assistant/status', $headers)
            ->assertOk()->assertJsonPath('data.can_write', false);
        $this->postJson('/api/v1/billing/assistant/chat', [
            'message' => 'Quando vence minha assinatura?', 'context' => ['page' => 'assinatura_bloqueada'],
        ], $headers)->assertOk()->assertJsonPath('data.intent', 'billing_status');
        $this->getJson('/api/v1/clients', $headers)->assertStatus(402);
    }

    public function test_context_registry_includes_billing_and_all_dev_sections(): void
    {
        $pages = config('ace_pages');
        $this->assertArrayHasKey('assinatura', $pages['workspace']);
        foreach (['dev-tenants','dev-users','dev-issues','dev-audit','dev-plans'] as $page) {
            $this->assertArrayHasKey($page, $pages['dev']);
        }
    }

    public function test_dev_ai_has_no_workspace_data_api_access(): void
    {
        $this->actingAs($this->dev());
        $this->getJson('/api/v1/dev/ai/status')->assertOk()->assertJsonPath('data.can_write', false);
        $this->postJson('/api/v1/dev/ai/chat', ['message' => 'Explique esta tela', 'context' => ['page' => 'dev-plans']])
            ->assertOk()->assertJsonPath('data.intent', 'dev_help');
        $this->getJson('/api/v1/clients')->assertForbidden();
        $this->postJson('/api/v1/ai/chat', ['message'=>'Mostre clientes', 'context'=>['page'=>'clientes']])->assertForbidden();
    }
}
