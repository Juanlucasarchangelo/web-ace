<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Messaging\MailEvents;
use App\Services\Messaging\CustomerRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CheckAceOverdue extends Command
{
    protected $signature = 'ace:billing-overdue-check';
    protected $description = 'Registra avisos únicos de assinaturas efetivamente vencidas, sem mudar contratos';
    public function handle(): int
    {
        Tenant::query()->where('billing_provider', 'mercadopago')
            ->where('billing_status', 'paid')->whereNotNull('billing_expires_at')
            ->where('billing_expires_at', '<', now())->chunkById(100, function ($tenants): void {
                foreach ($tenants as $tenant) {
                    DB::transaction(function () use ($tenant): void {
                        $current = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
                        if (! $current || $current->billing_provider !== 'mercadopago' || $current->billing_status !== 'paid'
                            || ! $current->billing_expires_at || ! $current->billing_expires_at->isPast()) return;
                        $expires = $current->billing_expires_at->toIso8601String();
                        MailEvents::record('invoice-overdue:'.$current->id.':'.$expires, 'invoice_overdue', [
                            'tenant_id' => $current->id, 'company' => $current->name, 'expires_at' => $expires,
                        ]);
                        if ($email = CustomerRecipient::forTenant($current)) {
                            MailEvents::record('customer-overdue:'.$current->id.':'.$expires, 'customer_overdue', [
                                'tenant_id' => $current->id, 'company' => $current->name, 'expires_at' => $expires,
                            ], $email);
                        }
                    });
                }
            });
        return self::SUCCESS;
    }
}
