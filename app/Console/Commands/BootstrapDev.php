<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Dedicated global operations identity with NO tenant membership or content privileges. */
class BootstrapDev extends Command
{
    protected $signature = 'ace:bootstrap-dev';
    protected $description = 'Cria um usuário DEV global separado, sem acesso aos dados dos workspaces';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Nome do operador DEV'));
        $email = Str::lower(trim((string) $this->ask('E-mail exclusivo do DEV')));
        $password = (string) $this->secret('Senha forte (mín. 14, maiúsculas, minúsculas, números e símbolos)');
        $confirm = (string) $this->secret('Repita a senha');
        $validator = Validator::make(compact('name', 'email', 'password', 'confirm'), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(14)->mixedCase()->numbers()->symbols()],
            'confirm' => ['same:password'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }
        $dev = User::create(['name' => $name, 'email' => $email, 'password' => $password,
            'is_active' => true, 'email_verified_at' => now()]);
        $dev->forceFill(['is_dev' => true])->save();
        $this->info('Usuário DEV criado com sucesso, sem vínculo a empresas. Proteja esta conta com uma senha exclusiva.');
        return self::SUCCESS;
    }
}
