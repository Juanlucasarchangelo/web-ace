<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

class ChangeTenantPlan extends Command
{
    protected $signature = 'ace:plan {tenant : Slug ou UUID da empresa} {plan : free|cloud|pro}';
    protected $description = 'Altera o plano de uma empresa (administrador do servidor; não é faturamento)';

    public function handle(): int
    {
        $plan = (string) $this->argument('plan');
        if (! in_array($plan, ['free', 'cloud', 'pro'], true)) {
            $this->error('Planos válidos: free, cloud, pro.');
            return self::FAILURE;
        }
        $tenant = Tenant::where('id', $this->argument('tenant'))->orWhere('slug', $this->argument('tenant'))->first();
        if (! $tenant) {
            $this->error('Empresa não encontrada.');
            return self::FAILURE;
        }
        $tenant->update(['plan' => $plan]);
        $this->info("Plano de {$tenant->name} atualizado para {$plan}.");
        return self::SUCCESS;
    }
}
