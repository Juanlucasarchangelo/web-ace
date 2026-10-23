<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class BootstrapAdmin extends Command
{
    protected $signature = 'vault:bootstrap-admin {--status : Retorna ready ou needs_setup sem alterar dados}';
    protected $description = 'Cria a primeira empresa e seu administrador (somente uma vez)';

    public function handle(): int
    {
        if ($this->option('status')) {
            $this->line(User::query()->exists() ? 'ready' : 'needs_setup');
            return self::SUCCESS;
        }

        if (User::query()->exists()) {
            $this->error('Inicialização negada: já existe usuário. Use um fluxo de convites após o bootstrap.');
            return self::FAILURE;
        }

        $company = trim((string) $this->ask('Nome da empresa'));
        $name = trim((string) $this->ask('Nome do administrador'));
        $email = Str::lower(trim((string) $this->ask('E-mail corporativo')));
        $password = (string) $this->secret('Senha forte (mínimo 12, maiúscula, minúscula, número e símbolo)');
        $confirmation = (string) $this->secret('Confirme a senha');

        $validator = Validator::make(compact('company', 'name', 'email', 'password', 'confirmation'), [
            'company' => ['required', 'string', 'max:140'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            'confirmation' => ['same:password'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }

        DB::transaction(function () use ($company, $name, $email, $password): void {
            $tenant = Tenant::query()->create([
                'name' => $company,
                'slug' => Str::slug($company).'-'.Str::lower(Str::random(6)),
                'is_active' => true,
            ]);
            $user = User::query()->create([
                'name' => $name, 'email' => $email,
                'password' => $password, 'email_verified_at' => now(), 'is_active' => true,
            ]);
            $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        });

        $this->info('Empresa e administrador criados. Nenhuma senha foi gravada em texto puro.');
        return self::SUCCESS;
    }
}
