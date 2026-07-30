<?php

namespace App\Providers;

use App\Contracts\ContentIntelligence;
use App\Contracts\OperationsNotifier;
use App\Contracts\Publisher;
use App\Services\MadelineOwnerLease;
use App\Services\OpenRouterContentIntelligence;
use App\Services\TelegramOperationsNotifier;
use App\Services\TelegramPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ContentIntelligence::class, OpenRouterContentIntelligence::class);
        $this->app->bind(OperationsNotifier::class, TelegramOperationsNotifier::class);
        $this->app->bind(Publisher::class, TelegramPublisher::class);
        $this->app->singleton(MadelineOwnerLease::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
