<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Support\PersistentStorage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('brevo', fn (array $config) => new BrevoTransport((string) ($config['key'] ?? '')));

        // لو PERSISTENT_STORAGE_PATH متظبط (Volume على Railway) اربط مسارات الكتابة بيه. idempotent ورخيص.
        if (!$this->app->runningUnitTests()) {
            PersistentStorage::ensure();
        }
    }
}
