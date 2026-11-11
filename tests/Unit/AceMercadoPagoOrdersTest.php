<?php

namespace Tests\Unit;

use App\Models\AceBillingOrder;
use App\Services\Billing\BillingActivator;
use App\Services\Billing\MercadoPagoGateway;
use App\Services\Billing\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AceMercadoPagoOrdersTest extends TestCase
{
    private function configureGateway(): void
    {
        config()->set('ace_billing.enabled', true);
        config()->set('ace_billing.environment', 'sandbox');
        config()->set('ace_billing.access_token', 'TEST-private-token');
        config()->set('ace_billing.webhook_secret', 'test-signing-secret');
        config()->set('ace_billing.seller_id', '12345');
        config()->set('ace_billing.public_url', 'https://ace.example.com');
        foreach (['legal_name','legal_document','legal_address','privacy_contact','support_contact'] as $key) {
            config()->set('ace_billing.'.$key, 'test-value');
        }
        config()->set('mail.default', 'smtp');
    }

    public function test_gateway_creates_only_an_idempotent_orders_api_request(): void
    {
        $this->configureGateway();
        $providerId = 'ORDTST01KS5AJ6HTK2HRQ3XJ3C2JCKP9';
        $localId = 'f22df9be-e91f-4b45-965e-50932e19fa1d';
        Http::fake([
            'api.mercadopago.com/v1/orders' => Http::response([
                'id' => $providerId,
                'checkout_url' => 'https://www.mercadopago.com.br/checkout/v1/redirect?order_id='.$providerId,
                'external_reference' => $localId, 'user_id' => '12345',
            ], 201),
        ]);
        $order = new AceBillingOrder([
            'id' => $localId, 'amount_cents' => 12900, 'currency' => 'BRL',
            'plan' => 'cloud', 'cycle' => 'monthly', 'plan_title' => 'Cloud',
        ]);
        $result = app(MercadoPagoGateway::class)->createOrder($order, 'buyer@example.com');
        $this->assertSame($providerId, $result['order_id']);
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://api.mercadopago.com/v1/orders'
            && $request->hasHeader('X-Idempotency-Key', $localId)
            && $request['processing_mode'] === 'manual'
            && $request['total_amount'] === '129.00'
            && $request['external_reference'] === $localId
        );
        Http::assertSentCount(1);
    }

    public function test_webhook_validates_alphanumeric_order_hmac_and_rejects_wrong_id(): void
    {
        $this->configureGateway();
        $providerId = 'ORDTST01KS5AJ6HTK2HRQ3XJ3C2JCKP9';
        $ts = (string) time();
        $requestId = 'req-test';
        $signature = hash_hmac('sha256', 'id:'.strtolower($providerId).';request-id:'.$requestId.';ts:'.$ts.';', 'test-signing-secret');
        $request = Request::create('/api/v1/billing/webhook?data.id='.$providerId.'&type=order', 'POST', [], [], [], [
            'HTTP_X_REQUEST_ID' => $requestId, 'HTTP_X_SIGNATURE' => 'ts='.$ts.',v1='.$signature,
        ], json_encode(['type' => 'order', 'data' => ['id' => $providerId], 'live_mode' => false]));
        $this->assertSame($providerId, WebhookSignature::orderId($request));
        $this->assertTrue(app(WebhookSignature::class)->valid($request, $providerId));
        $this->assertFalse(app(WebhookSignature::class)->valid($request, 'ORDTEST000000000000001'));
    }

    public function test_money_is_strict_and_never_float_rounded(): void
    {
        $this->assertSame(12900, BillingActivator::toCents('129.00'));
        $this->assertSame(130800, BillingActivator::toCents('1308'));
        $this->assertSame(1, BillingActivator::toCents('0.01'));
        $this->assertNull(BillingActivator::toCents(129.00));
        $this->assertNull(BillingActivator::toCents('1e4'));
        $this->assertNull(BillingActivator::toCents('129.001'));
        $this->assertNull(BillingActivator::toCents('-100.00'));
    }

    public function test_wrong_amount_fails_closed_before_provisioning(): void
    {
        $this->configureGateway();
        $providerId = 'ORDTST01KS5AJ6HTK2HRQ3XJ3C2JCKP9';
        $order = new AceBillingOrder([
            'id' => 'f22df9be-e91f-4b45-965e-50932e19fa1d',
            'provider_preference_id' => $providerId,
            'amount_cents' => 12900, 'currency' => 'BRL',
        ]);
        $this->expectException(RuntimeException::class);
        app(BillingActivator::class)->settleOrder($order, [
            'id' => $providerId, 'external_reference' => $order->id,
            'user_id' => '12345', 'type' => 'online', 'processing_mode' => 'manual',
            'currency' => 'BRL', 'total_amount' => '1.00', 'total_paid_amount' => '1.00',
            'status' => 'processed', 'status_detail' => 'accredited',
        ]);
    }
}
