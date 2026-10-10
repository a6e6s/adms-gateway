<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateBioTimeClient;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
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
        $this->app->bind(MiddlewareAuthSecurityStrategy::class, fn (): MiddlewareAuthSecurityStrategy => new MiddlewareAuthSecurityStrategy(
            middleware: [AuthenticateBioTimeClient::class],
            scheme: SecurityScheme::apiKey('header', 'Authorization')
                ->as('BioTimeToken')
                ->setDescription('Enter jwt or Token followed by a space and the token returned by POST /jwt-api-token-auth/. Example: jwt <your-token>.'),
        ));
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
