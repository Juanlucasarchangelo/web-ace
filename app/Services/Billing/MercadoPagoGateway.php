<?php

namespace App\Services\Billing;

use App\Models\AceBillingOrder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Checkout Pro via Mercado Pago Orders API. Only the backend sees the access token. */
final class MercadoPagoGateway
{
    /** Gateway API access must not depend on whether new checkouts are enabled. */
    public function canFetchOrders(): bool
    {
        return trim((string) config('ace_billing.access_token')) !== ''
            && ctype_digit((string) config('ace_billing.seller_id'))
            && in_array(config('ace_billing.environment'), ['production', 'sandbox'], true);
    }

    public function configured(): bool
    {
        if (! $this->canFetchOrders()) return false;

        $url = rtrim((string) config('ace_billing.public_url'), '/');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $public = ($parts['scheme'] ?? '') === 'https' && $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)
            && ! str_ends_with($host, '.localhost');

        foreach (['access_token', 'webhook_secret', 'seller_id', 'legal_name', 'legal_document', 'legal_address', 'privacy_contact', 'support_contact'] as $key) {
            if (! trim((string) config('ace_billing.'.$key))) return false;
        }
        // Email verification must never rely on a mail log or an in-memory transport.
        return (bool) config('ace_billing.enabled') && $public
            && in_array(config('ace_billing.environment'), ['production', 'sandbox'], true)
            && ctype_digit((string) config('ace_billing.seller_id'))
            && ! in_array((string) config('mail.default'), ['log', 'array', 'null'], true);
    }

    /** Creates one provider order per ACE order. Repeating with the same UUID is idempotent. */
    public function createOrder(AceBillingOrder $order, string $payerEmail): array
    {
        if (! $this->configured()) throw new RuntimeException('Checkout ainda não configurado.');
        if ($order->amount_cents < 1 || $order->currency !== 'BRL') throw new RuntimeException('Valor do pedido inválido.');

        $base = rtrim((string) config('ace_billing.public_url'), '/');
        $return = $base.'/pagamento?pedido='.$order->id;
        $money = number_format($order->amount_cents / 100, 2, '.', '');
        $payload = [
            'type' => 'online',
            'processing_mode' => 'manual',
            'external_reference' => (string) $order->id,
            'total_amount' => $money,
            'description' => 'ACE '.($order->plan_title ?: ucfirst($order->plan)),
            'payer' => ['email' => $payerEmail],
            'items' => [[
                'title' => 'ACE '.($order->plan_title ?: ucfirst($order->plan)).' - '.($order->cycle === 'annual' ? '12 meses' : '1 mês'),
                'unit_price' => $money,
                'quantity' => 1,
            ]],
            'config' => ['online' => [
                'success_url' => $return,
                'pending_url' => $return,
                'failure_url' => $return,
                'auto_return' => 'approved',
            ]],
        ];

        $response = Http::withToken((string) config('ace_billing.access_token'))
            ->withHeaders(['X-Idempotency-Key' => (string) $order->id])
            ->acceptJson()->connectTimeout(5)->timeout(22)
            ->post('https://api.mercadopago.com/v1/orders', $payload);

        if ($response->status() !== 201) throw new RuntimeException('Falha ao criar order no Mercado Pago.');
        $id = $response->json('id');
        $link = $response->json('checkout_url');
        $seller = (string) $response->json('user_id', '');
        if (! is_string($id) || ! self::validOrderId($id)
            || ! is_string($link) || ! $this->trustedCheckoutUrl($link)
            || $seller !== (string) config('ace_billing.seller_id')
            || $response->json('external_reference') !== $order->id) {
            throw new RuntimeException('Resposta do provedor incompatível com o pedido.');
        }
        return ['order_id' => $id, 'checkout_url' => $link];
    }

    /** Fetch authoritative data. Never accept the status sent by the browser or by webhook body. */
    public function fetchOrder(string $providerOrderId): array
    {
        // Reconciliation must continue working even during a temporary
        // checkout pause or an email outage. Approval remains guarded by
        // BillingActivator's seller, amount, currency and status checks.
        if (! $this->canFetchOrders() || ! self::validOrderId($providerOrderId)) {
            throw new RuntimeException('Consulta de order indisponível.');
        }
        $response = Http::withToken((string) config('ace_billing.access_token'))
            ->acceptJson()->connectTimeout(5)->timeout(15)
            ->get('https://api.mercadopago.com/v1/orders/'.$providerOrderId);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['id'] ?? null) !== $providerOrderId) {
            throw new RuntimeException('Não foi possível consultar a order no provedor.');
        }
        return $data;
    }

    public static function validOrderId(string $id): bool
    {
        // ORD... and ORDTST... identifiers from the documented Orders API.
        return (bool) preg_match('/^ORD[A-Z0-9]{10,76}$/iD', $id);
    }

    private function trustedCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) return false;
        return $host === 'mercadopago.com.br' || str_ends_with($host, '.mercadopago.com.br')
            || $host === 'mercadopago.com' || str_ends_with($host, '.mercadopago.com');
    }
}
