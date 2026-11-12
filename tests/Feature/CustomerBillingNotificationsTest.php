<?php

namespace Tests\Feature;

use App\Models\AceMessagingSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\CustomerRecipient;
use App\Services\Messaging\MailEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerBillingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_uses_checkout_address_instead_of_login_address(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa ABC', 'slug' => 'abc', 'plan' => 'pro',
            'is_active' => true, 'seat_limit' => 2,
            'billing_notification_email' => 'CONTAS@EXAMPLE.COM']);
        $owner = User::factory()->create(['email' => 'login@example.com', 'is_active' => true]);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);
        $this->assertSame('contas@example.com', CustomerRecipient::forTenant($tenant));
    }

    public function test_customer_events_do_not_fall_back_to_dev_recipients(): void
    {
        $dev = User::factory()->create(['is_active' => true]);
        $dev->forceFill(['is_dev' => true, 'dev_notification_emails' => ['dev@example.com']])->save();
        AceMessagingSetting::create(['id' => 1, 'enabled' => true,
            'resend_api_key' => 're_key_customer_tests_123456',
            'from_email' => 'avisos@example.com', 'from_name' => 'ACE']);
        MailEvents::record('customer-payment:test', 'customer_payment', ['company' => 'ABC'], 'pagamento@example.com');
        MailEvents::record('payment-approved:test', 'new_payment', ['company' => 'ABC']);
        MailEvents::record('customer-without-email:test', 'customer_payment', ['company' => 'ABC']);
        Http::fake(['https://api.resend.com/*' => Http::response(['id' => 'email-id'], 200)]);
        $this->artisan('ace:mail-process')->assertSuccessful();
        $this->artisan('ace:mail-process')->assertSuccessful();
        $this->assertDatabaseCount('ace_mail_deliveries', 2);
        $this->assertSame(1, DB::table('ace_mail_deliveries')->where('recipient_email', 'pagamento@example.com')->count());
        $this->assertSame(1, DB::table('ace_mail_deliveries')->where('recipient_email', 'dev@example.com')->count());
        Http::assertSentCount(2);
    }

    public function test_customer_overdue_is_cancelled_if_renewed_before_delivery(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa Renovada', 'slug' => 'renovada',
            'plan' => 'pro', 'is_active' => true, 'seat_limit' => 2,
            'billing_notification_email' => 'financeiro@example.com',
            'billing_status' => 'paid', 'billing_provider' => 'mercadopago',
            'billing_expires_at' => now()->subDay()]);
        $this->artisan('ace:billing-overdue-check')->assertSuccessful();
        $this->assertDatabaseHas('ace_mail_events', ['event_type' => 'customer_overdue']);
        $tenant->forceFill(['billing_expires_at' => now()->addMonth()])->save();
        AceMessagingSetting::create(['id' => 1, 'enabled' => true,
            'resend_api_key' => 're_key_customer_tests_123456',
            'from_email' => 'avisos@example.com', 'from_name' => 'ACE']);
        Http::fake();
        $this->artisan('ace:mail-process')->assertSuccessful();
        $this->assertSame(0, DB::table('ace_mail_deliveries')->count());
        $this->assertSame(2, DB::table('ace_mail_events')->whereNotNull('cancelled_at')->count());
        Http::assertNothingSent();
    }
}
