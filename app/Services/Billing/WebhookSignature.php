<?php

namespace App\Services\Billing;

use Illuminate\Http\Request;

/** Validates signed Order webhooks; never trusts an unsigned body to grant access. */
final class WebhookSignature
{
    public static function orderId(Request $request): ?string
    {
        // PHP converts the literal query key "data.id" to "data_id". Access
        // the array, not Laravel's dotted-path accessor, so both are handled.
        $query = $request->query();
        $rawId = $query['data.id'] ?? $query['data_id'] ?? null;

        if (! is_string($rawId) || ! MercadoPagoGateway::validOrderId($rawId)) {
            return null;
        }

        return $rawId;
    }

    public function valid(Request $request, string $resourceId): bool
    {
        if (! MercadoPagoGateway::validOrderId($resourceId)) {
            return false;
        }

        $secret = (string) config('ace_billing.webhook_secret');
        $requestId = (string) $request->header('x-request-id', '');
        $header = (string) $request->header('x-signature', '');

        // Keep a strict signature requirement: no missing fields, no bypass.
        if ($secret === '' || $requestId === ''
            || ! preg_match('/(?:^|,)\s*ts=(\d{10,16})(?=,|$)/', $header, $timestampPart)
            || ! preg_match('/(?:^|,)\s*v1=([a-f0-9]{64})(?=,|$)/i', $header, $digestPart)) {
            return false;
        }

        // The provider uses seconds or milliseconds. Compare the normalized
        // timestamp for freshness; sign the original string, without changes.
        $seconds = (int) $timestampPart[1];
        if ($seconds > 100000000000) {
            $seconds = intdiv($seconds, 1000);
        }
        if (abs(time() - $seconds) > 900) {
            return false;
        }

        $manifest = 'id:'.strtolower($resourceId)
            .';request-id:'.$requestId
            .';ts:'.$timestampPart[1].';';

        return hash_equals(
            strtolower($digestPart[1]),
            hash_hmac('sha256', $manifest, $secret)
        );
    }
}
