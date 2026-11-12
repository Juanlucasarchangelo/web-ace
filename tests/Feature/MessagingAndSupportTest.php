<?php

namespace Tests\Feature;

use App\Models\AceMessagingSetting;
use App\Models\AceSupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessagingAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private function dev(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['is_dev' => true])->save();
        return $user;
    }

    private function owner(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'plan' => 'free',
            'is_active' => true, 'seat_limit' => 4]);
        $user = User::factory()->create(['is_active' => true]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);
        return [$tenant, $user];
    }

    public function test_notification_recipients_are_not_the_login_email_and_are_dev_only(): void
    {
        $dev = $this->dev();
        $this->actingAs($dev)->putJson('/api/v1/auth/profile/notifications', [
            'emails' => ['Financeiro@example.com', 'suporte@example.com', 'financeiro@example.com'],
        ])->assertOk()->assertJsonPath('data.dev_notification_emails', ['financeiro@example.com', 'suporte@example.com']);
        $this->assertEquals(['financeiro@example.com', 'suporte@example.com'], $dev->fresh()->dev_notification_emails);
        $this->assertNotContains($dev->email, $dev->fresh()->dev_notification_emails);
        [, $user] = $this->owner('empresa-um');
        $this->actingAs($user)->putJson('/api/v1/auth/profile/notifications', ['emails'=>['other@example.com']])->assertForbidden();
    }

    public function test_dev_configures_encrypted_resend_key_and_can_test_send(): void
    {
        $dev = $this->dev();
        $this->actingAs($dev)->putJson('/api/v1/auth/profile/notifications', [
            'emails' => ['financeiro@example.com'],
        ])->assertOk();
        $this->putJson('/api/v1/dev/messaging', [
            'enabled'=>true, 'from_name'=>'ACE', 'from_email'=>'avisos@example.com',
            'resend_api_key'=>'re_secret_key_example_1234567890',
            'event_preferences'=>['new_payment'=>true, 'new_signup'=>true],
        ])->assertOk();
        $this->assertNotSame('re_secret_key_example_1234567890',
            DB::table('ace_messaging_settings')->value('resend_api_key'));
        $this->getJson('/api/v1/dev/messaging')->assertOk()->assertJsonPath('data.configured', true)
            ->assertDontSee('re_secret_key_example_1234567890');
        Http::fake(['https://api.resend.com/*' => Http::response(['id'=>'resend-test-abc'], 200)]);
        $this->postJson('/api/v1/dev/messaging/test', ['email'=>'financeiro@example.com'])->assertOk();
        Http::assertSentCount(1);
        $this->postJson('/api/v1/dev/messaging/test', ['email'=>'unknown@example.com'])->assertUnprocessable();
    }

    public function test_member_creates_ticket_and_dev_replies_without_tenant_leakage(): void
    {
        [$tenantA, $ownerA] = $this->owner('empresa-a');
        [$tenantB, $ownerB] = $this->owner('empresa-b');
        $dev = $this->dev();
        $created = $this->actingAs($ownerA)->postJson('/api/v1/support/tickets', [
            'subject'=>'Problema com uma licença', 'category'=>'technical',
            'priority'=>'normal', 'message'=>'O sistema apresentou uma mensagem de erro ao abrir.',
        ])->assertCreated();
        $id = $created->json('data.id');
        $this->assertDatabaseHas('ace_mail_events', ['event_type'=>'ticket_opened']);
        $this->actingAs($ownerB)->getJson('/api/v1/support/tickets/'.$id)->assertNotFound();
        $this->actingAs($dev)->getJson('/api/v1/dev/support/tickets/'.$id)->assertOk()
            ->assertJsonPath('data.tenant_id', $tenantA->id);
        $this->postJson('/api/v1/dev/support/tickets/'.$id.'/reply', [
            'message'=>'Recebemos o chamado e estamos verificando.',
        ])->assertOk();
        $this->assertSame('answered', AceSupportTicket::findOrFail($id)->status);
        $this->actingAs($ownerA)->getJson('/api/v1/support/tickets/'.$id)->assertOk()
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_outbox_sends_once_to_each_configured_dev_email_without_login_fallback(): void
    {
        $dev = $this->dev();
        $dev->forceFill(['dev_notification_emails' => ['financeiro@example.com', 'suporte@example.com']])->save();
        AceMessagingSetting::create(['id'=>1, 'enabled'=>true,
            'resend_api_key'=>'re_test_key_123456789012345',
            'from_name'=>'ACE','from_email'=>'avisos@example.com']);
        \App\Services\Messaging\MailEvents::record('payment-approved:test-one', 'new_payment', [
            'company'=>'Cliente ABC', 'plan'=>'pro', 'amount_cents'=>19900, 'order'=>'pedido-um',
        ]);
        Http::fake(['https://api.resend.com/*' => Http::response(['id'=>'resend-id'], 200)]);
        $this->artisan('ace:mail-process')->assertSuccessful();
        $this->artisan('ace:mail-process')->assertSuccessful();
        $this->assertDatabaseCount('ace_mail_deliveries', 2);
        $this->assertSame(2, DB::table('ace_mail_deliveries')->where('status','sent')->count());
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => in_array($dev->email, $r->data()['to'] ?? [], true));
    }

    public function test_overdue_is_recorded_once_and_does_not_send_stale_notice_after_renewal(): void
    {
        [$tenant] = $this->owner('empresa-overdue');
        $tenant->forceFill(['billing_provider'=>'mercadopago', 'billing_status'=>'paid',
            'billing_expires_at'=>now()->subDays(2)])->save();
        $this->artisan('ace:billing-overdue-check')->assertSuccessful();
        $this->artisan('ace:billing-overdue-check')->assertSuccessful();
        $this->assertSame(1, DB::table('ace_mail_events')->where('event_type','invoice_overdue')->count());
        $tenant->forceFill(['billing_expires_at'=>now()->addMonth()])->save();
        AceMessagingSetting::create(['id'=>1, 'enabled'=>true,'resend_api_key'=>'re_dummy_key_test',
            'from_email'=>'avisos@example.com', 'from_name'=>'ACE']);
        $dev = $this->dev();
        $dev->forceFill(['dev_notification_emails'=>['dev@example.com']])->save();
        Http::fake();
        $this->artisan('ace:mail-process')->assertSuccessful();
        $this->assertSame(1, DB::table('ace_mail_events')->whereNotNull('cancelled_at')->count());
        Http::assertNothingSent();
    }
}
