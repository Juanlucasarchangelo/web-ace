<?php

namespace App\Services\Billing;

use App\Models\AceBillingOrder;
use App\Models\AceSignupApplication;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\MailEvents;
use App\Services\Messaging\CustomerRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Provisioning requires authenticated provider data, explicit approval and DB row locks. */
final class BillingActivator
{
    public function provision(AceSignupApplication $signup, ?AceBillingOrder $paidOrder = null): Tenant
    {
        if (! $signup->email_verified_at) {
            throw new RuntimeException('Cadastro não validado: confirme o e-mail do Master.');
        }
        if ($signup->tenant_id) return Tenant::findOrFail($signup->tenant_id);
        if (User::where('email', $signup->email)->exists()) {
            throw new RuntimeException('Conta já existente; intervenção administrativa necessária.');
        }
        $user = User::create([
            'name' => $signup->name, 'email' => $signup->email,
            'password' => $signup->password_hash, 'is_active' => true,
            'email_verified_at' => $signup->email_verified_at ?? now(),
        ]);
        $tenant = Tenant::create([
            'name' => $signup->company,
            'slug' => Str::slug($signup->company).'-'.Str::lower(Str::random(10)),
            'is_active' => true, 'plan' => $signup->plan, 'seat_limit' => 1,
            'billing_status' => $paidOrder ? 'paid' : 'free',
            'billing_provider' => $paidOrder ? 'mercadopago' : 'free',
            'billing_cycle' => $paidOrder?->cycle,
            'billing_expires_at' => $paidOrder?->period_end,
            'billing_checked_at' => now(),
            'billing_notification_email' => CustomerRecipient::valid($paidOrder?->checkout_email) ?: CustomerRecipient::valid($signup->email),
        ]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);
        $signup->forceFill(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active'])->save();
        MailEvents::record('signup-activated:'.$tenant->id, 'new_signup', [
            'company' => $tenant->name, 'plan' => $tenant->plan, 'name' => $user->name,
        ]);
        if ($email = CustomerRecipient::forTenant($tenant)) {
            MailEvents::record('customer-signup:'.$tenant->id, 'customer_signup', [
                'company' => $tenant->name, 'plan' => $tenant->plan,
            ], $email);
        }
        return $tenant;
    }

    public function activateFree(AceSignupApplication $signup): Tenant
    {
        if ($signup->plan !== 'free' || ! $signup->email_verified_at) throw new RuntimeException('E-mail não confirmado.');
        return $this->provision($signup);
    }

    /**
     * Must be called inside a transaction with the local billing order locked.
     * Only the provider's GET /v1/orders/{id} result is accepted.
     */
    public function settleOrder(AceBillingOrder $order, array $data): void
    {
        $providerId = (string) ($data['id'] ?? '');
        $paymentRows = $data['transactions']['payments'] ?? [];
        $payments = is_array($paymentRows) ? $paymentRows : [];
        $status = strtolower((string) ($data['status'] ?? ''));
        $detail = strtolower((string) ($data['status_detail'] ?? ''));
        $amount = self::toCents($data['total_amount'] ?? null);
        $paid = self::toCents($data['total_paid_amount'] ?? null);
        $expectedLive = config('ace_billing.environment') === 'production';

        if (! MercadoPagoGateway::validOrderId($providerId)
            || $order->provider_preference_id !== $providerId
            || (string) ($data['external_reference'] ?? '') !== (string) $order->id
            || (string) ($data['user_id'] ?? '') !== (string) config('ace_billing.seller_id')
            || ($data['currency'] ?? '') !== 'BRL'
            || ($data['type'] ?? '') !== 'online'
            || ($data['processing_mode'] ?? '') !== 'manual'
            || $amount === null || $amount !== (int) $order->amount_cents
            || $order->currency !== 'BRL'
            || (array_key_exists('live_mode', $data) && $data['live_mode'] !== $expectedLive)) {
            throw new RuntimeException('Order não conciliada: identidade, ambiente, vendedor ou valor incompatível.');
        }

        $reversal = in_array($status, ['refunded','charged_back','chargeback'], true)
            || in_array($detail, ['refunded','partially_refunded','charged_back','chargeback','reversed','disputed'], true);
        foreach ($payments as $payment) {
            if (! is_array($payment)) continue;
            if (in_array(strtolower((string) ($payment['status'] ?? '')), ['refunded','charged_back','chargeback'], true)
                || in_array(strtolower((string) ($payment['status_detail'] ?? '')), ['refunded','partially_refunded','charged_back','chargeback'], true)) {
                $reversal = true;
            }
        }
        if ($reversal) {
            if ($order->status !== 'approved') return;
            $oldEnd = $order->period_end;
            $order->forceFill(['status' => 'refunded'])->save();
            if ($order->tenant_id) {
                $tenant = Tenant::whereKey($order->tenant_id)->lockForUpdate()->first();
                if ($tenant && $tenant->billing_provider === 'mercadopago' && $oldEnd
                    && $tenant->billing_expires_at?->equalTo($oldEnd)) {
                    $tenant->forceFill(['billing_status' => 'refunded', 'billing_expires_at' => now(), 'billing_checked_at' => now()])->save();
                }
            }
            return;
        }

        $verifiedPayment = null;
        foreach ($payments as $payment) {
            if (! is_array($payment)) continue;
            $id = (string) ($payment['id'] ?? '');
            if ($id !== '' && strlen($id) <= 80
                && ($payment['status'] ?? null) === 'processed'
                && ($payment['status_detail'] ?? null) === 'accredited') {
                $verifiedPayment = $payment;
                break;
            }
        }
        // Fail closed: neither a webhook nor an incomplete/partial payment grants access.
        if ($status === 'processed' && $detail === 'accredited'
            && $paid !== null && $paid === (int) $order->amount_cents
            && $verifiedPayment !== null) {
            if ($order->status === 'approved' || in_array($order->status, ['refunded','charged_back'], true)) return;
            $paymentId = (string) $verifiedPayment['id'];
            if ($order->provider_payment_id && $order->provider_payment_id !== $paymentId) return;

            $base = now();
            $tenant = null;
            if ($order->tenant_id) {
                $tenant = Tenant::whereKey($order->tenant_id)->lockForUpdate()->firstOrFail();
                if ($tenant->billing_expires_at && $tenant->billing_expires_at->isFuture() && $tenant->billing_provider === 'mercadopago') {
                    $base = $tenant->billing_expires_at->copy();
                }
            }
            $end = $order->cycle === 'annual' ? $base->copy()->addYearNoOverflow() : $base->copy()->addMonthNoOverflow();
            $order->forceFill([
                'status' => 'approved', 'provider_payment_id' => $paymentId,
                'payment_method' => Str::limit((string) ($verifiedPayment['payment_method']['type'] ?? 'unknown'), 40, ''),
                'paid_at' => now(), 'period_end' => $end,
            ])->save();
            if ($order->signup_application_id) {
                $signup = AceSignupApplication::whereKey($order->signup_application_id)->lockForUpdate()->firstOrFail();
                $tenant = $this->provision($signup, $order);
                $order->forceFill(['tenant_id' => $signup->tenant_id])->save();
            } else {
                $tenant->forceFill([
                    'plan' => $order->plan, 'billing_provider' => 'mercadopago',
                    'billing_status' => 'paid', 'billing_cycle' => $order->cycle,
                    'billing_expires_at' => $end, 'billing_checked_at' => now(),
                    'billing_notification_email' => CustomerRecipient::valid($order->checkout_email)
                        ?: CustomerRecipient::forTenant($tenant),
                ])->save();
            }
            $company = $tenant?->name ?? ($signup?->company ?? 'Empresa');
            MailEvents::record('payment-approved:'.$order->id, 'new_payment', [
                'company' => $company, 'plan' => $order->plan,
                'amount_cents' => (int) $order->amount_cents, 'order' => $order->id,
            ]);
            if ($email = CustomerRecipient::valid($order->checkout_email) ?: CustomerRecipient::forTenant($tenant)) {
                MailEvents::record('customer-payment:'.$order->id, 'customer_payment', [
                    'company' => $company, 'plan' => $order->plan,
                    'amount_cents' => (int) $order->amount_cents, 'order' => $order->id,
                ], $email);
            }
            if (! $order->signup_application_id) {
                MailEvents::record('invoice-renewed:'.$order->id, 'invoice_renewed', [
                    'company' => $company, 'amount_cents' => (int) $order->amount_cents,
                    'expires_at' => $end->toIso8601String(),
                ]);
                if ($email = CustomerRecipient::valid($order->checkout_email) ?: CustomerRecipient::forTenant($tenant)) {
                    MailEvents::record('customer-renewed:'.$order->id, 'customer_renewed', [
                        'company' => $company, 'amount_cents' => (int) $order->amount_cents,
                        'expires_at' => $end->toIso8601String(),
                    ], $email);
                }
            }
        } elseif (! in_array($order->status, ['approved','refunded','charged_back'], true)) {
            $order->forceFill(['status' => in_array($status, ['created','pending','processing','action_required'], true) ? 'pending' : 'rejected'])->save();
        }
    }

    /** Parse BRL without floating-point rounding or permissive numeric casts. */
    public static function toCents(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_int($amount)) return null;
        $text = (string) $amount;
        if (! preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/D', $text, $parts)) return null;
        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }
}
