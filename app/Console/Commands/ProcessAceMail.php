<?php
namespace App\Console\Commands;

use App\Services\Messaging\MailOutbox;
use Illuminate\Console\Command;

final class ProcessAceMail extends Command
{
    protected $signature = 'ace:mail-process {--limit=30}';
    protected $description = 'Entrega alertas transacionais pendentes pelo Resend, com deduplicação e reenvio';
    public function handle(MailOutbox $processor): int
    {
        $result = $processor->process((int) $this->option('limit'));
        $this->info(json_encode($result));
        return self::SUCCESS;
    }
}
