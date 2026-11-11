<?php

namespace App\Console\Commands;

use App\Models\GoogleDriveConnection;
use App\Services\GoogleDriveHealth;
use Illuminate\Console\Command;

class CheckDriveConnections extends Command
{
    protected $signature = 'ace:check-drive-connections';
    protected $description = 'Testa autorização OAuth de Drive por refresh, sem consultar arquivos ou credenciais de clientes';

    public function handle(GoogleDriveHealth $health): int
    {
        $checked = 0;
        GoogleDriveConnection::query()->orderBy('id')->chunkById(50, function ($connections) use ($health, &$checked): void {
            foreach ($connections as $connection) {
                $health->check($connection);
                $checked++;
            }
        });
        $this->info("Conexões verificadas: {$checked}. Confira o painel DEV para diagnósticos.");
        return self::SUCCESS;
    }
}
