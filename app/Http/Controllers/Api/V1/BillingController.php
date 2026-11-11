<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AceBillingOrder;
use App\Models\AceSignupApplication;
use App\Models\Tenant;
use App\Models\AcePlan;
use App\Services\Billing\BillingActivator;
use App\Services\Billing\AffiliateCoupons;
use App\Services\Billing\MercadoPagoGateway;
use App\Services\Billing\WebhookSignature;
use App\Support\BillingPlans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BillingController extends Controller
{
    public function catalog(MercadoPagoGateway $gateway): JsonResponse
    {
        return response()->json(['data' => BillingPlans::catalog() + [
            'checkout_ready' => $gateway->configured(),
            'legal_version' => config('ace_billing.legal_version'),
            'supplier' => [
                'name' => (string) config('ace_billing.legal_name'),
                'cnpj' => (string) config('ace_billing.legal_document'),
                'address' => (string) config('ace_billing.legal_address'),
                'privacy_contact' => (string) config('ace_billing.privacy_contact'),
                'support_contact' => (string) config('ace_billing.support_contact'),
            ],
        ]]);
    }

    public function couponQuote(Request $request, AffiliateCoupons $coupons): JsonResponse
    {
        $input = $request->validate([
            'plan' => ['required', Rule::in(['cloud','pro'])],
            'cycle' => ['required', Rule::in(['monthly','annual'])],
            'coupon' => ['required','string','max:32'],
        ]);
        $quote = $coupons->quote($input['plan'], $input['cycle'], $input['coupon']);
        return response()->json(['data' => [
            'coupon' => $quote['coupon_code'], 'discount_percent' => $quote['discount_percent'],
            'original_amount_cents' => $quote['original_amount_cents'],
            'discount_cents' => $quote['discount_cents'], 'amount_cents' => $quote['amount_cents'],
        ]])->header('Cache-Control','no-store');
    }

    public function register(Request $request, MercadoPagoGateway $gateway, AffiliateCoupons $coupons): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required','string','min:2','max:140'],
            'company' => ['required','string','min:2','max:140'],
            'email' => ['required','email:rfc','max:255'],
            'password' => ['required','string','min:12','max:128','confirmed'],
            'plan' => ['required', Rule::in(['free','cloud','pro'])],
            'cycle' => ['required', Rule::in(['monthly','annual'])],
            'coupon' => ['nullable','string','max:32'],
            'accept_terms' => ['required','accepted'],
            'acknowledge_privacy' => ['required','accepted'],
            'terms_version' => ['required', Rule::in([config('ace_billing.legal_version')])],
            'privacy_version' => ['required', Rule::in([config('ace_billing.legal_version')])],
        ]);
        $this->assertPlanAvailable($input['plan'], $input['cycle']);
        $input['email'] = Str::lower(trim($input['email']));
        if ($input['plan'] === 'free' && config('app.env') === 'production'
            && in_array(config('mail.default'), ['log','array'], true)) {
            return response()->json(['message' => 'O cadastro gratuito está temporariamente indisponível. O envio de e-mail de verificação precisa ser configurado.'], 503);
        }
        if (\App\Models\User::where('email', $input['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'Esse e-mail já possui uma conta. Entre ou procure o suporte.']);
        }
        // A pending signup may be saved while payments are paused. No paid access is granted.
        // Price and coupon will be rechecked before any new provider order is created.
        $couponCode = AffiliateCoupons::normalize($input['coupon'] ?? null);
        // Reservation/limit is checked under the coupon row lock when inserting a new application.

        // An existing pending application may be retried only by someone with its password.
        $app = AceSignupApplication::where('email', $input['email'])->first();
        if ($app) {
            $existingCoupon = $app->affiliate_coupon_id
                ? \App\Models\AceAffiliateCoupon::whereKey($app->affiliate_coupon_id)->value('code') : '';
            if (! Hash::check($input['password'], $app->password_hash) || $app->plan !== $input['plan']
                || $app->cycle !== $input['cycle'] || $app->status === 'active'
                || $existingCoupon !== $couponCode) {
                throw ValidationException::withMessages(['email' => 'Já existe uma solicitação para este e-mail. Procure o suporte.']);
            }
        } else {
            $app = DB::transaction(function () use ($input, $couponCode, $coupons, $request) {
                // Lock the coupon row before checking quota and inserting the reservation.
                if ($couponCode !== '') {
                    \App\Models\AceAffiliateCoupon::where('code', $couponCode)->lockForUpdate()->first();
                    $coupons->quote($input['plan'], $input['cycle'], $couponCode);
                }
                return AceSignupApplication::create([
                'name' => $input['name'], 'email' => $input['email'], 'company' => $input['company'],
                'password_hash' => Hash::make($input['password']), 'plan' => $input['plan'],
                'cycle' => $input['cycle'], 'status' => 'pending',
                'affiliate_coupon_id' => $couponCode !== '' ? \App\Models\AceAffiliateCoupon::where('code', $couponCode)->value('id') : null,
                'terms_version' => $input['terms_version'], 'privacy_version' => $input['privacy_version'],
                'accepted_at' => now(),
                'acceptance_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                ]);
            });
        }

        if (! $app->email_verified_at) {
            if (in_array((string) config('mail.default'), ['log','array','null'], true)) {
                return response()->json(['data' => [
                    'status' => 'email_waiting_configuration',
                    'message' => 'Pré-cadastro registrado. A confirmação do e-mail será liberada quando o serviço de envio for configurado. Nenhuma cobrança foi iniciada.',
                ]], 202);
            }
            $token = Str::random(64);
            $app->forceFill([
                'status' => 'email_pending',
                'email_verification_hash' => hash('sha256', $token),
                'email_verification_sent_at' => now(),
            ])->save();
            $baseUrl = trim((string) config('ace_billing.public_url')) ?: (string) config('app.url');
            $link = rtrim($baseUrl, '/').'/verificar-email?solicitacao='.$app->id.'&codigo='.$token;
            try {
                Mail::raw("Confirme o e-mail do Master para continuar o cadastro ACE.\n\n$link\n\nLink válido por 24 horas. Se você não solicitou, ignore.",
                    fn ($message) => $message->to($app->email)->subject('ACE — confirme seu e-mail'));
            } catch (\Throwable $exception) {
                report(new RuntimeException('Falha ao enviar e-mail de confirmação do ACE.'));
                return response()->json(['message' => 'Não foi possível entregar o e-mail de confirmação. Tente novamente ou procure o suporte.'], 503);
            }
            return response()->json(['data' => [
                'status' => 'email_pending',
                'message' => 'Enviamos um link para confirmação do e-mail. Você só continuará para o pagamento após verificar o endereço.',
            ]], 202);
        }

        if ($app->plan === 'free') {
            return response()->json(['message' => 'E-mail já confirmado. Entre com sua conta ACE Free.']);
        }

        $order = $this->getOrCreateSignupOrder($app, $coupons);
        return $this->createCheckout($gateway, $order, $app->email);
    }

    private function getOrCreateSignupOrder(AceSignupApplication $app, AffiliateCoupons $coupons): AceBillingOrder
    {
        // One checkout ID per verified signup, even under simultaneous browser requests.
        return DB::transaction(function () use ($app, $coupons): AceBillingOrder {
            $locked = AceSignupApplication::whereKey($app->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->email_verified_at && $locked->status !== 'active', 409);
            $order = AceBillingOrder::where('signup_application_id', $locked->id)
                ->whereIn('status', ['initiated', 'pending', 'gateway_error'])
                ->where(function ($query): void {
                    $query->whereNull('provider_preference_id')->orWhere('provider_preference_id', 'like', 'ORD%');
                })->orderByDesc('created_at')->first();
            if ($order) return $order;
            // Lock coupon to keep the usage limit consistent for concurrent signups.
            $coupon = $locked->affiliate_coupon_id
                ? \App\Models\AceAffiliateCoupon::whereKey($locked->affiliate_coupon_id)->lockForUpdate()->first() : null;
            $quote = $coupons->quote($locked->plan, $locked->cycle, $coupon?->code, $locked->id);
            return AceBillingOrder::create([
                'signup_application_id' => $locked->id,
                'plan' => $locked->plan, 'cycle' => $locked->cycle,
                'plan_title' => AcePlan::findOrFail($locked->plan)->title,
                'amount_cents' => $quote['amount_cents'],
                'original_amount_cents' => $quote['original_amount_cents'],
                'discount_cents' => $quote['discount_cents'],
                'affiliate_coupon_id' => $coupon?->id,
                'currency' => 'BRL', 'status' => 'initiated',
                'terms_version' => $locked->terms_version, 'privacy_version' => $locked->privacy_version,
                'accepted_at' => $locked->accepted_at,
            ]);
        });
    }

    public function verifyEmail(Request $request, BillingActivator $activator, MercadoPagoGateway $gateway, AffiliateCoupons $coupons): JsonResponse
    {
        $data = $request->validate([
            'application_id' => ['required', 'uuid'], 'token' => ['required', 'string', 'size:64'],
        ]);
        $app = DB::transaction(function () use ($data, $activator): AceSignupApplication {
            $app = AceSignupApplication::whereKey($data['application_id'])->lockForUpdate()->firstOrFail();
            abort_if($app->status === 'active', 409, 'Solicitação já concluída.');
            if (! $app->email_verification_hash || ! $app->email_verification_sent_at
                || $app->email_verification_sent_at->lt(now()->subDay())
                || ! hash_equals($app->email_verification_hash, hash('sha256', $data['token']))) {
                abort(422, 'Este link de verificação é inválido ou expirou.');
            }
            $app->forceFill(['email_verified_at' => now(), 'email_verification_hash' => null, 'status' => 'verified'])->save();
            if ($app->plan === 'free') $activator->activateFree($app);
            return $app;
        });
        if ($app->plan === 'free') {
            return response()->json(['message' => 'E-mail confirmado. Sua conta gratuita está ativa. Entre com seu e-mail e senha.']);
        }
        if (! $gateway->configured()) return response()->json(['message' => 'E-mail confirmado. O checkout será disponibilizado após a configuração do Mercado Pago.'], 503);
        $this->assertPlanAvailable($app->plan, $app->cycle);
        return $this->createCheckout($gateway, $this->getOrCreateSignupOrder($app, $coupons), $app->email);
    }

    public function orderStatus(Request $request, AceBillingOrder $order): JsonResponse
    {
        // The order UUID is not authorization. A separate, HttpOnly bearer cookie is mandatory.
        // Legacy orders without a tracking hash are deliberately not publicly queryable.
        $provided = (string) $request->cookie($this->trackingCookieName($order));
        if (! $order->tracking_token_hash || ! preg_match('/^[A-Za-z0-9]{64}$/D', $provided)
            || ! hash_equals((string) $order->tracking_token_hash, hash('sha256', $provided))) {
            abort(404);
        }
        return response()->json(['data' => [
            'status' => $order->status, 'plan' => $order->plan,
            'active' => $order->status === 'approved',
            'period_end' => $order->status === 'approved' ? $order->period_end?->toIso8601String() : null,
        ]])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(! $user || $user->is_dev, 403);
        $tenantId = $request->header('X-Tenant-Id');
        $tenant = $user->tenants()->where('tenants.is_active', true)->orderBy('tenants.created_at')->first();
        abort_if($tenantId && $tenant && $tenantId !== $tenant->id, 403);
        abort_unless($tenant, 403);
        return response()->json(['data' => [
            'plan' => $tenant->plan, 'plan_name' => AcePlan::query()->whereKey($tenant->plan)->value('title') ?: ucfirst($tenant->plan), 'cycle' => $tenant->billing_cycle,
            'provider' => $tenant->billing_provider, 'status' => $tenant->billing_status,
            'expires_at' => $tenant->billing_expires_at?->toIso8601String(),
            'blocked' => self::blocked($tenant), 'can_renew' => $tenant->pivot->role === 'owner',
        ]]);
    }

    public function renew(Request $request, MercadoPagoGateway $gateway): JsonResponse
    {
        $input = $request->validate([
            'plan' => ['required', Rule::in(['cloud','pro'])],
            'cycle' => ['required', Rule::in(['monthly','annual'])],
            'accept_terms' => ['required','accepted'],
            'acknowledge_privacy' => ['required','accepted'],
            'terms_version' => ['required', Rule::in([config('ace_billing.legal_version')])],
        ]);
        $this->assertPlanAvailable($input['plan'], $input['cycle']);
        abort_unless($gateway->configured(), 503, 'O checkout ainda não está configurado.');
        $user = $request->user();
        abort_if(! $user || $user->is_dev, 403);
        $tenantId = $request->header('X-Tenant-Id');
        $tenant = $user->tenants()->where('tenants.is_active', true)->orderBy('tenants.created_at')->first();
        abort_if($tenantId && $tenant && $tenantId !== $tenant->id, 403);
        abort_unless($tenant && $tenant->pivot->role === 'owner', 403, 'Somente o Master pode renovar a assinatura.');
        $order = DB::transaction(function () use ($tenant, $input): AceBillingOrder {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = AceBillingOrder::where('tenant_id', $tenant->id)
                ->where('plan', $input['plan'])->where('cycle', $input['cycle'])
                ->whereIn('status', ['initiated','pending','gateway_error'])
                ->where('created_at', '>=', now()->subHour())
                ->where(function ($query): void {
                    $query->whereNull('provider_preference_id')->orWhere('provider_preference_id', 'like', 'ORD%');
                })->orderByDesc('created_at')->first();
            if ($existing) return $existing;
            return AceBillingOrder::create([
                'tenant_id' => $tenant->id, 'plan' => $input['plan'], 'cycle' => $input['cycle'],
                'plan_title' => AcePlan::findOrFail($input['plan'])->title,
                'amount_cents' => BillingPlans::price($input['plan'], $input['cycle']),
                'currency' => 'BRL', 'status' => 'initiated',
                'terms_version' => config('ace_billing.legal_version'), 'privacy_version' => config('ace_billing.legal_version'),
                'accepted_at' => now(),
            ]);
        });
        return $this->createCheckout($gateway, $order, $user->email);
    }

    /** Checkout Pro using the Orders API. Column name is retained for DB compatibility. */
    private function createCheckout(MercadoPagoGateway $gateway, AceBillingOrder $order, string $email): JsonResponse
    {
        if ($order->checkout_url && $order->provider_preference_id
            && MercadoPagoGateway::validOrderId($order->provider_preference_id)
            && $order->status !== 'approved') {
            return $this->checkoutTrackingResponse($order, [
                'order_id' => $order->id, 'checkout_url' => $order->checkout_url, 'status' => $order->status,
            ]);
        }
        // Never mix an old Preferences checkout with an Orders API checkout.
        if ($order->provider_preference_id && ! MercadoPagoGateway::validOrderId($order->provider_preference_id)) {
            return response()->json(['message' => 'Pedido antigo: solicite uma nova cobrança.'], 409);
        }
        try {
            $provider = $gateway->createOrder($order, $email);
            // Do not overwrite a concurrently confirmed order.
            $order->refresh();
            if ($order->status === 'approved') {
                return $this->checkoutTrackingResponse($order, ['order_id' => $order->id, 'status' => 'approved']);
            }
            if ($order->provider_preference_id && $order->provider_preference_id !== $provider['order_id']) {
                throw new RuntimeException('Idempotência do provedor incompatível.');
            }
            $order->forceFill([
                'provider_preference_id' => $provider['order_id'],
                'checkout_url' => $provider['checkout_url'], 'status' => 'pending',
            ])->save();
            return $this->checkoutTrackingResponse($order, [
                'order_id' => $order->id, 'checkout_url' => $provider['checkout_url'], 'status' => 'pending',
            ], 201);
        } catch (\Throwable $exception) {
            AceBillingOrder::whereKey($order->id)->whereNotIn('status', ['approved','refunded','charged_back'])
                ->update(['status' => 'gateway_error']);
            report(new RuntimeException('Falha ao criar order no Mercado Pago.'));
            return response()->json(['message' => 'Pagamento temporariamente indisponível. Nenhum acesso foi liberado.'], 503);
        }
    }

    /** Mint a separate tracking capability; never put it in redirect URLs or JS responses. */
    private function checkoutTrackingResponse(AceBillingOrder $order, array $data, int $status = 200): JsonResponse
    {
        $token = Str::random(64);
        $order->forceFill(['tracking_token_hash' => hash('sha256', $token)])->save();

        // Checkout operates on a public HTTPS origin. Restrict cookie to status API only.
        // SameSite=Lax survives the browser's return from Mercado Pago; HttpOnly hides it from JS.
        $cookie = cookie($this->trackingCookieName($order), $token, 10080,
            '/api/v1/billing/orders', null, true, true, false, 'lax');

        return response()->json(['data' => $data], $status)
            ->cookie($cookie)
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    private function trackingCookieName(AceBillingOrder $order): string
    {
        return 'ace_order_'.str_replace('-', '', (string) $order->id);
    }

    /** Signed notifications only identify an order; its status always comes from Mercado Pago's API. */
    public function webhook(Request $request, WebhookSignature $signature, MercadoPagoGateway $gateway, BillingActivator $activator): JsonResponse
    {
        $id = WebhookSignature::orderId($request);
        if (! $id || ! $signature->valid($request, $id)) {
            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }
        if ($request->query('type') !== 'order' || $request->input('type') !== 'order'
            || (string) $request->input('data.id') !== $id
            || ! is_bool($request->input('live_mode'))
            || $request->input('live_mode') !== (config('ace_billing.environment') === 'production')) {
            return response()->json(['message' => 'Evento incompatível.'], 400);
        }
        // A signed simulator notification may carry a valid-looking ID that
        // does not belong to this ACE installation. Acknowledge it without
        // consulting the provider or creating any subscription. This also
        // prevents arbitrary provider API lookups from Webhook notifications.
        if (! AceBillingOrder::where('provider_preference_id', $id)->exists()) {
            return response()->json(['message' => 'Evento recebido; order não vinculada.']);
        }

        try {
            $provider = $gateway->fetchOrder($id);
            $reference = (string) ($provider['external_reference'] ?? '');
            if (! Str::isUuid($reference)) return response()->json(['message' => 'Evento ignorado.']);
            DB::transaction(function () use ($reference, $provider, $activator, $id): void {
                $order = AceBillingOrder::whereKey($reference)->lockForUpdate()->first();
                if (! $order || $order->provider_preference_id !== $id) return;
                $activator->settleOrder($order, $provider);
                DB::table('ace_billing_events')->insert([
                    'order_id' => $order->id,
                    'provider_payment_id' => $id,
                    'event_status' => Str::limit((string) ($provider['status'] ?? 'unknown'), 32, ''),
                    'event_code' => 'verified.order',
                    'created_at' => now(),
                ]);
            });
            return response()->json(['message' => 'Order verificada.']);
        } catch (\Throwable $exception) {
            report(new RuntimeException('Falha na conciliação Orders; provedor será notificado para tentar novamente.'));
            return response()->json(['message' => 'Conciliação indisponível.'], 503);
        }
    }

    private function assertPlanAvailable(string $plan, string $cycle): void
    {
        try { BillingPlans::price($plan, $cycle); }
        catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }
    }

    public static function blocked(Tenant $tenant): bool
    {
        return $tenant->billing_provider === 'mercadopago'
            && ($tenant->billing_status !== 'paid' || ! $tenant->billing_expires_at || $tenant->billing_expires_at->isPast());
    }
}
