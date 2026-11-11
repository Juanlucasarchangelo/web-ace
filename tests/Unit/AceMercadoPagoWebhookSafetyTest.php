<?php

namespace Tests\Unit;

use App\Services\Billing\MercadoPagoGateway;
use App\Services\Billing\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AceMercadoPagoWebhookSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_ID = 'ORDTST01KS5AJ6HTK2HRQ3XJ3C2JCKP9';

    private function signatureHeaders(string $id, ?string $ts = null, string $requestId = 'request-ace'): array
    {
        $ts ??= (string) time();
        $manifest = 'id:'.strtolower($id).';request-id:'.$requestId.';ts:'.$ts.';';
        $hash = hash_hmac('sha256', $manifest, 'ace-test-webhook-secret');

        return ['x-request-id' => $requestId, 'x-signature' => 'ts='.$ts.',v1='.$hash];
    }

    private function configureWebhook(): void
    {
        config()->set('ace_billing.webhook_secret', 'ace-test-webhook-secret');
        config()->set('ace_billing.environment', 'sandbox');
        config()->set('ace_billing.enabled', false);
    }

    public function test_webhook_rejects_unsigned_notification(): void
    {
        $this->configureWebhook();
        $this->postJson('/api/v1/billing/webhook?data.id='.self::ORDER_ID.'&type=order', [
            'type' => 'order', 'live_mode' => false, 'data' => ['id' => self::ORDER_ID],
        ])->assertStatus(401);
    }

    public function test_signed_simulator_event_for_unknown_order_is_acknowledged_without_api_call_or_activation(): void
    {
        $this->configureWebhook();
        Http::fake();
        $this->postJson('/api/v1/billing/webhook?data.id='.self::ORDER_ID.'&type=order', [
            'type' => 'order', 'live_mode' => false, 'data' => ['id' => self::ORDER_ID],
        ], $this->signatureHeaders(self::ORDER_ID))
            ->assertOk()
            ->assertJsonPath('message', 'Evento recebido; order não vinculada.');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ace_billing_orders', 0);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_signed_order_with_wrong_body_id_is_rejected(): void
    {
        $this->configureWebhook();
        $this->postJson('/api/v1/billing/webhook?data.id='.self::ORDER_ID.'&type=order', [
            'type' => 'order', 'live_mode' => false, 'data' => ['id' => 'ORDTEST0000000000000000001'],
        ], $this->signatureHeaders(self::ORDER_ID))->assertStatus(400);
    }

    public function test_signed_live_mode_event_is_not_accepted_in_sandbox(): void
    {
        $this->configureWebhook();
        $this->postJson('/api/v1/billing/webhook?data.id='.self::ORDER_ID.'&type=order', [
            'type' => 'order', 'live_mode' => true, 'data' => ['id' => self::ORDER_ID],
        ], $this->signatureHeaders(self::ORDER_ID))->assertStatus(400);
    }

    public function test_tampered_signature_and_expired_timestamp_are_denied(): void
    {
        $this->configureWebhook();
        $url = '/api/v1/billing/webhook?data.id='.self::ORDER_ID.'&type=order';
        $body = ['type' => 'order', 'live_mode' => false, 'data' => ['id' => self::ORDER_ID]];

        $headers = $this->signatureHeaders(self::ORDER_ID);
        $headers['x-request-id'] = 'tampered';
        $this->postJson($url, $body, $headers)->assertStatus(401);
        $this->postJson($url, $body, $this->signatureHeaders(self::ORDER_ID, (string) (time() - 901)))
            ->assertStatus(401);
    }

    public function test_ms_timestamp_is_supported_but_hmac_uses_original_timestamp(): void
    {
        $this->configureWebhook();
        $timestamp = (string) (time() * 1000);
        $request = Request::create(
            '/api/v1/billing/webhook?data.id='.self::ORDER_ID.'&type=order',
            'POST', [], [], [],
            ['HTTP_X_REQUEST_ID' => 'request-ace',
                'HTTP_X_SIGNATURE' => $this->signatureHeaders(self::ORDER_ID, $timestamp)['x-signature']]
        );
        $this->assertTrue(app(WebhookSignature::class)->valid($request, self::ORDER_ID));
    }

    public function test_order_fetch_can_reconcile_existing_payments_even_when_checkout_and_email_are_disabled(): void
    {
        config()->set('ace_billing.enabled', false);
        config()->set('ace_billing.environment', 'sandbox');
        config()->set('ace_billing.access_token', 'TEST-ace-access-token');
        config()->set('ace_billing.seller_id', '12345');
        config()->set('mail.default', 'log');
        Http::fake(['api.mercadopago.com/v1/orders/*' => Http::response([
            'id' => self::ORDER_ID, 'status' => 'created',
        ], 200)]);

        $this->assertFalse(app(MercadoPagoGateway::class)->configured());
        $this->assertTrue(app(MercadoPagoGateway::class)->canFetchOrders());
        $order = app(MercadoPagoGateway::class)->fetchOrder(self::ORDER_ID);
        $this->assertSame(self::ORDER_ID, $order['id']);
        Http::assertSentCount(1);
    }
}
