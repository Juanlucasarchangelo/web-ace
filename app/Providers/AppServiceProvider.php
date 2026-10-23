<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $key = Str::lower(trim((string) $request->input('email'))).'|'.$request->ip();
            return [Limit::perMinute(5)->by($key), Limit::perMinute(30)->by('ip:'.$request->ip())];
        });
    }
}
