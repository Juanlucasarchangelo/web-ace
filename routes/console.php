<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('vault:info', function (): void {
    $this->info('ACE · aplicação Laravel 11 / API v1.');
});
