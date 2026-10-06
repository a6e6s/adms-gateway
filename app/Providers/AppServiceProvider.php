<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();

        RateLimiter::for('biotime-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['detail' => 'Request was throttled.'], 429, $headers)));

        RateLimiter::for('biotime-read', fn (Request $request) => Limit::perMinute(120)->by((string) $request->attributes->get('biotime_client')->id)
            ->response(fn (Request $request, array $headers) => response()->json(['detail' => 'Request was throttled.'], 429, $headers)));
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
