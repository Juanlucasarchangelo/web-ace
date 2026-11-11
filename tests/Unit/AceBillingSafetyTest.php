<?php

namespace Tests\Unit;

use App\Models\AceBillingOrder;
use App\Models\Tenant;
use App\Http\Controllers\Api\V1\BillingController;
use App\Services\Billing\MercadoPagoGateway;
use App\Support\BillingPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AceBillingSafetyTest extends TestCase
{
    use RefreshDatabase;
    public function test_prices_are_server_owned_and_annual_values_are_whole_periods(): void
    {
        $this->assertSame(0, BillingPlans::price('free', 'monthly'));
        $this->assertSame(12900, BillingPlans::price('cloud', 'monthly'));
        $this->assertSame(15900, BillingPlans::price('pro', 'monthly'));
        $this->assertSame(130800, BillingPlans::price('cloud', 'annual'));
        $this->assertSame(166800, BillingPlans::price('pro', 'annual'));
    }

    public function test_gateway_stays_disabled_when_credentials_or_public_domain_are_missing(): void
    {
        config()->set('ace_billing.enabled', true);
        config()->set('ace_billing.access_token', 'test-token');
        config()->set('ace_billing.webhook_secret', 'test-secret');
        config()->set('ace_billing.seller_id', '12345');
        foreach (['legal_name','legal_document','legal_address','privacy_contact','support_contact'] as $key) config()->set('ace_billing.'.$key, 'test');
        config()->set('ace_billing.public_url', 'http://localhost:8088');
        $this->assertFalse(app(MercadoPagoGateway::class)->configured());
    }

    public function test_gateway_subscription_expires_without_marking_legacy_accounts_as_blocked(): void
    {
        $legacy = new Tenant(['billing_provider'=>'manual', 'billing_status'=>'unverified']);
        $paid = new Tenant(['billing_provider'=>'mercadopago','billing_status'=>'paid','billing_expires_at'=>now()->subDay()]);
        $this->assertFalse(BillingController::blocked($legacy));
        $this->assertTrue(BillingController::blocked($paid));
    }
}
