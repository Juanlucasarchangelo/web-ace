<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('vault:info', function (): void {
    $this->info('ACE · aplicação Laravel 13 / API v1.');
});

// Requires ACE scheduler container; checks OAuth refresh only and never accesses files.
Schedule::command('ace:check-drive-connections')->dailyAt('03:10')->withoutOverlapping();

// Webhooks são primários; esta verificação identifica pagamentos e estornos não notificados.
Schedule::command('ace:billing-reconcile --limit=100')->everyFifteenMinutes()->withoutOverlapping();

// Envio em segundo plano: checkout/cadastro nunca dependem da resposta do Resend.
Schedule::command('ace:mail-process --limit=30')->everyMinute()->withoutOverlapping();
Schedule::command('ace:billing-overdue-check')->dailyAt('08:15')->timezone('America/Sao_Paulo')->withoutOverlapping();
