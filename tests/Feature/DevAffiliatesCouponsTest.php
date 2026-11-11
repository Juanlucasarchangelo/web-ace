<?php

namespace Tests\Feature;

use App\Models\AceAffiliate;
use App\Models\AceAffiliateCoupon;
use App\Models\AceBillingOrder;
use App\Models\AceSignupApplication;
use App\Models\User;
use App\Services\Billing\AffiliateCoupons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevAffiliatesCouponsTest extends TestCase
{
    use RefreshDatabase;

    private function dev(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_dev' => true])->save();
        return $user;
    }

    public function test_non_dev_cannot_manage_affiliates(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/dev/affiliates')->assertForbidden();
        $this->postJson('/api/v1/dev/affiliates', ['user_id' => $user->id])->assertForbidden();
        $this->postJson('/api/v1/dev/affiliates/coupons', [])->assertForbidden();
    }

    public function test_dev_creates_affiliate_and_coupon_with_server_owned_discount(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->dev());
        $affiliateId = $this->postJson('/api/v1/dev/affiliates', ['user_id' => $user->id])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/dev/affiliates', ['user_id' => $user->id])->assertUnprocessable();
        $this->postJson('/api/v1/dev/affiliates/coupons', [
            'affiliate_id' => $affiliateId, 'code' => 'PARCEIRO15',
            'discount_percent' => 15, 'plan' => 'cloud', 'max_uses' => 2,
        ])->assertCreated();
        $quote = $this->postJson('/api/v1/billing/coupon/quote', [
            'coupon' => 'parceiro15', 'plan' => 'cloud', 'cycle' => 'monthly',
        ])->assertOk();
        $this->assertSame(12900, $quote->json('data.original_amount_cents'));
        $this->assertSame(1935, $quote->json('data.discount_cents'));
        $this->assertSame(10965, $quote->json('data.amount_cents'));
        $this->postJson('/api/v1/billing/coupon/quote', [
            'coupon' => 'PARCEIRO15', 'plan' => 'pro', 'cycle' => 'monthly',
        ])->assertUnprocessable();
    }

    public function test_usage_metrics_distinguish_signup_from_paid_orders(): void
    {
        $user = User::factory()->create();
        $affiliate = AceAffiliate::create(['user_id' => $user->id]);
        $coupon = AceAffiliateCoupon::create(['affiliate_id' => $affiliate->id, 'code' => 'INDIQUE25', 'discount_percent' => 25]);
        $signup = AceSignupApplication::create([
            'name' => 'Responsável', 'email' => 'cliente@example.test', 'company' => 'Empresa',
            'password_hash' => 'not-a-real-password-hash', 'plan' => 'cloud', 'cycle' => 'monthly',
            'status' => 'pending', 'terms_version' => '2026-11-08', 'privacy_version' => '2026-11-08',
            'accepted_at' => now(), 'affiliate_coupon_id' => $coupon->id,
        ]);
        AceBillingOrder::create([
            'signup_application_id' => $signup->id, 'plan' => 'cloud', 'cycle' => 'monthly',
            'plan_title' => 'Cloud', 'amount_cents' => 9675, 'original_amount_cents' => 12900,
            'discount_cents' => 3225, 'affiliate_coupon_id' => $coupon->id,
            'terms_version' => '2026-11-08', 'privacy_version' => '2026-11-08',
            'accepted_at' => now(), 'status' => 'pending',
        ]);
        $this->actingAs($this->dev())->getJson('/api/v1/dev/affiliates')->assertOk()
            ->assertJsonPath('data.coupons.0.uses', 1)
            ->assertJsonPath('data.coupons.0.orders', 1)
            ->assertJsonPath('data.coupons.0.paid_sales', 0)
            ->assertJsonPath('data.coupons.0.paid_revenue_cents', 0);
    }

    public function test_public_pre_signup_reserves_coupon_without_email_delivery_or_payment(): void
    {
        config()->set('mail.default', 'log');
        config()->set('ace_billing.enabled', false);
        $affiliateUser = User::factory()->create();
        $affiliate = AceAffiliate::create(['user_id' => $affiliateUser->id]);
        $coupon = AceAffiliateCoupon::create([
            'affiliate_id' => $affiliate->id, 'code' => 'VENDA15',
            'discount_percent' => 15, 'max_uses' => 1,
        ]);
        $payload = [
            'name' => 'Pessoa Compradora', 'company' => 'Empresa Cliente',
            'email' => 'novo-cliente@example.test', 'password' => 'senha-segura-de-teste-2026',
            'password_confirmation' => 'senha-segura-de-teste-2026',
            'plan' => 'cloud', 'cycle' => 'monthly', 'coupon' => 'VENDA15',
            'accept_terms' => true, 'acknowledge_privacy' => true,
            'terms_version' => config('ace_billing.legal_version'),
            'privacy_version' => config('ace_billing.legal_version'),
        ];
        $this->postJson('/api/v1/billing/signup', $payload)->assertStatus(202)
            ->assertJsonPath('data.status', 'email_waiting_configuration');
        $this->assertDatabaseHas('ace_signup_applications', [
            'email' => 'novo-cliente@example.test', 'affiliate_coupon_id' => $coupon->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('ace_billing_orders', 0);
        $this->postJson('/api/v1/billing/coupon/quote', [
            'coupon' => 'VENDA15', 'plan' => 'cloud', 'cycle' => 'monthly',
        ])->assertUnprocessable();
    }

    public function test_disabled_coupon_is_not_validated_and_no_payment_can_be_activated(): void
    {
        $user = User::factory()->create();
        $affiliate = AceAffiliate::create(['user_id' => $user->id]);
        $coupon = AceAffiliateCoupon::create(['affiliate_id' => $affiliate->id, 'code' => 'PARCEIRO20', 'discount_percent' => 20]);
        $this->actingAs($this->dev())->patchJson('/api/v1/dev/affiliates/coupons/'.$coupon->id,
            ['is_active' => false])->assertOk();
        $this->postJson('/api/v1/billing/coupon/quote', [
            'coupon' => 'PARCEIRO20', 'plan' => 'cloud', 'cycle' => 'monthly',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('ace_billing_orders', 0);
    }
}
