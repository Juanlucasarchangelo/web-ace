<?php

namespace App\Console\Commands;

use App\Models\AceBillingOrder;
use App\Services\Billing\BillingActivator;
use App\Services\Billing\MercadoPagoGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reconciles created/paid Orders using authoritative GET /v1/orders/{id}. */
final class ReconcileAceBilling extends Command
{
    protected $signature = 'ace:billing-reconcile {--limit=100}';
    protected $description = 'Reconcilia Orders ACE no Mercado Pago sem confiar em retornos de navegador';

    public function handle(MercadoPagoGateway $gateway, BillingActivator $activator): int
    {
        if (! $gateway->canFetchOrders()) {
            $this->info('Credenciais do Mercado Pago indisponíveis: conciliação não executada.');
            return self::SUCCESS;
        }
        $limit = max(1, min(250, (int) $this->option('limit')));
        $orders = AceBillingOrder::query()
            ->whereNotNull('provider_preference_id')
            ->where(function ($q): void {
                $q->where(function ($pending): void {
                    $pending->whereIn('status', ['initiated','pending','rejected','gateway_error'])
                        ->where('created_at', '>=', now()->subDays(60));
                })->orWhere(function ($active): void {
                    $active->where('status', 'approved')->where('period_end', '>=', now());
                });
            })->orderByDesc('created_at')->limit($limit)->get(['id','provider_preference_id']);
        $checked = 0;
        $errors = 0;
        foreach ($orders as $order) {
            if (! MercadoPagoGateway::validOrderId((string) $order->provider_preference_id)) continue;
            try {
                $provider = $gateway->fetchOrder($order->provider_preference_id);
                DB::transaction(function () use ($order, $provider, $activator): void {
                    $locked = AceBillingOrder::whereKey($order->id)->lockForUpdate()->first();
                    if (! $locked || $locked->provider_preference_id !== ($provider['id'] ?? null)) return;
                    $activator->settleOrder($locked, $provider);
                    DB::table('ace_billing_events')->insert([
                        'order_id' => $locked->id,
                        'provider_payment_id' => $locked->provider_preference_id,
                        'event_status' => substr((string) ($provider['status'] ?? 'unknown'), 0, 32),
                        'event_code' => 'verified.reconciled',
                        'created_at' => now(),
                    ]);
                });
                $checked++;
            } catch (\Throwable $e) {
                $errors++;
                report(new RuntimeException('ACE: falha ao reconciliar order no Mercado Pago.'));
            }
        }
        $this->info("Orders consultadas: {$checked}. Falhas: {$errors}.");
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
