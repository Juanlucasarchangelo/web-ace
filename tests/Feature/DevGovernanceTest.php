<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DevOperationalIssue;
use App\Models\GoogleDriveConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\GoogleDriveHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DevGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $slug, int $seats = 1): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create([
            'name' => $slug, 'slug' => $slug, 'is_active' => true,
            'plan' => 'cloud', 'seat_limit' => $seats,
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        return [$user, $tenant];
    }

    private function dev(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_dev' => true])->save();
        return $user;
    }

    public function test_dev_is_a_separate_identity_with_no_tenant_data_access(): void
    {
        [$owner, $tenant] = $this->owner('empresa-um');
        $other = User::factory()->create();
        $tenant->users()->attach($other->id, ['role' => 'member']);
        $dev = $this->dev();

        $this->actingAs($dev)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.role', 'dev')->assertJsonPath('data.tenant', null);
        $this->getJson('/api/v1/dev/overview')->assertOk()->assertJsonPath('data.users', 3);
        foreach (['/api/v1/clients', '/api/v1/sites', '/api/v1/vault', '/api/v1/team', '/api/v1/dashboard', '/api/v1/workspaces', '/api/v1/cloud/google/files'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->actingAs($owner)->getJson('/api/v1/dev/users')->assertForbidden();
    }

    public function test_dev_dashboard_returns_only_operational_metadata_never_passwords_or_files(): void
    {
        [$owner, $tenant] = $this->owner('empresa-a');
        $connection = GoogleDriveConnection::create([
            'tenant_id' => $tenant->id, 'connected_by' => $owner->id, 'email' => 'conta@example.test',
            'access_token' => 'secret-access-token', 'refresh_token' => 'secret-refresh-token',
        ]);
        $dev = $this->dev();
        $response = $this->actingAs($dev)->getJson('/api/v1/dev/tenants/'.$tenant->id.'/connections')->assertOk()
            ->assertJsonPath('data.accounts.0.id', $connection->id);
        $response->assertDontSee('secret-access-token')->assertDontSee('secret-refresh-token')
            ->assertDontSee('scope')->assertDontSee('files');
        $this->getJson('/api/v1/dev/tenants')->assertOk()->assertJsonPath('data.data.0.drive_count', 1);
    }

    public function test_only_master_creates_users_and_dev_controls_capacity(): void
    {
        [$owner, $tenant] = $this->owner('empresa-b', 1);
        $admin = User::factory()->create();
        $tenant->users()->attach($admin->id, ['role' => 'admin']);
        $payload = ['name' => 'Nova Pessoa', 'email' => 'nova@example.test', 'password' => 'Ficticia!123456', 'role' => 'member'];
        $this->actingAs($admin)->postJson('/api/v1/team', $payload)->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/team', $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'nova@example.test']);
        $dev = $this->dev();
        $this->actingAs($dev)->patchJson('/api/v1/dev/tenants/'.$tenant->id.'/seats', ['seat_limit' => 3])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/team', $payload)->assertCreated();
        $this->postJson('/api/v1/team', ['name' => 'Extra', 'email' => 'extra@example.test', 'password' => 'Ficticia!123456', 'role' => 'viewer'])->assertUnprocessable();
        $this->actingAs($dev)->patchJson('/api/v1/dev/tenants/'.$tenant->id.'/seats', ['seat_limit' => 1])->assertUnprocessable();
        $this->actingAs($owner)->patchJson('/api/v1/dev/tenants/'.$tenant->id.'/seats', ['seat_limit' => 50])->assertForbidden();
    }

    public function test_dev_can_set_manual_billing_without_claiming_payment_gateway_verification(): void
    {
        [, $tenant] = $this->owner('empresa-c');
        $dev = $this->dev();
        $this->actingAs($dev)->patchJson('/api/v1/dev/tenants/'.$tenant->id.'/billing', [
            'billing_status' => 'paid', 'plan' => 'pro',
        ])->assertOk()->assertJsonPath('data.billing_source', 'manual');
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'billing_status' => 'paid', 'plan' => 'pro']);
        $this->getJson('/api/v1/dev/overview')->assertOk();
    }

    public function test_google_oauth_invalid_grant_creates_sanitized_issue_and_dev_cannot_read_files(): void
    {
        [$owner, $tenant] = $this->owner('empresa-d');
        $conn = GoogleDriveConnection::create([
            'tenant_id' => $tenant->id, 'connected_by' => $owner->id,
            'email' => 'conta@example.test', 'access_token' => 'secret-token',
            'refresh_token' => 'secret-refresh-token', 'expires_at' => now()->subHours(1),
        ]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant', 'error_description' => 'SECRET that should never be logged',
        ], 400)]);
        $result = app(GoogleDriveHealth::class)->check($conn);
        $this->assertSame('reconnect_required', $result['status']);
        $issue = DevOperationalIssue::query()->latest('id')->firstOrFail();
        $this->assertSame('invalid_grant', $issue->code);
        $this->assertStringNotContainsString('SECRET', $issue->summary);
        $this->assertSame($tenant->id, $issue->tenant_id);
        $dev = $this->dev();
        $this->actingAs($dev)->getJson('/api/v1/dev/issues')->assertOk()
            ->assertSee('invalid_grant')->assertDontSee('SECRET')->assertDontSee('secret-token');
        $this->getJson('/api/v1/cloud/google/files')->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_dev_checks_oauth_without_calling_drive_files_api(): void
    {
        [$owner, $tenant] = $this->owner('empresa-e');
        $conn = GoogleDriveConnection::create([
            'tenant_id' => $tenant->id, 'connected_by' => $owner->id,
            'email' => 'conta@example.test', 'access_token' => 'old-token',
            'refresh_token' => 'existing-refresh', 'expires_at' => now()->subHour(),
        ]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-token', 'expires_in' => 3600,
        ], 200)]);
        $dev = $this->dev();
        $this->actingAs($dev)->postJson('/api/v1/dev/tenants/'.$tenant->id.'/connections/'.$conn->id.'/check')
            ->assertOk()->assertJsonPath('data.status', 'connected')->assertDontSee('new-token');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
    }
}
