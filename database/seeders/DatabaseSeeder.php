<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Local demo only. Never changes an existing account or tenant. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (config('app.env') !== 'local' || ! filter_var(env('ACE_DEMO_SEED_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->command?->info('Seed demo desativada: nenhum usuário modificado.');
            return;
        }
        // Never fill an incompletely migrated/restored installation: investigate it instead.
        if (User::query()->exists() || Tenant::query()->exists()) {
            $this->command?->info('Dados existentes detectados. Seed demo ignorada, sem alterar contas.');
            return;
        }
        $devPass = (string) env('ACE_DEMO_DEV_PASSWORD', '');
        $userPass = (string) env('ACE_DEMO_USER_PASSWORD', '');
        if (strlen($devPass) < 14 || strlen($userPass) < 14 || $devPass === $userPass) {
            throw new RuntimeException('Configure ACE_DEMO_DEV_PASSWORD e ACE_DEMO_USER_PASSWORD com senhas distintas e no mínimo 14 caracteres.');
        }
        DB::transaction(function () use ($devPass, $userPass): void {
            $dev = User::query()->create([
                'name' => 'Administrador DEV ACE', 'email' => 'ace@ace.com.br',
                'password' => $devPass, 'is_active' => true, 'email_verified_at' => now(),
            ]);
            $dev->forceFill(['is_dev' => true])->save();

            $tenant = Tenant::query()->create([
                'name' => 'Empresa Demonstração ACE',
                'slug' => 'ace-demonstracao-'.Str::lower(Str::random(8)),
                'is_active' => true, 'plan' => 'free', 'seat_limit' => 5,
                'billing_provider' => 'manual', 'billing_status' => 'unverified',
            ]);
            $owner = User::query()->create([
                'name' => 'Usuário Teste ACE', 'email' => 'teste@ace.com.br',
                'password' => $userPass, 'is_active' => true, 'email_verified_at' => now(),
            ]);
            $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
        });
        $this->command?->info('DEV e usuário teste locais criados. Altere as senhas antes de qualquer exposição na internet.');
    }
}
