<?php

namespace App\Providers;

use App\Services\Import\CsvImportService;
use App\Services\Verification\SmtpEmailVerifier;
use App\Services\Verification\VerificationScheduler;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmtpEmailVerifier::class, fn () => new SmtpEmailVerifier(config('verifier.smtp')));

        $this->app->singleton(
            VerificationScheduler::class,
            fn () => new VerificationScheduler(config('verifier.scheduler'))
        );

        $this->app->singleton(
            CsvImportService::class,
            fn () => new CsvImportService(config('verifier.scheduler'))
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
