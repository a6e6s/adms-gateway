<?php

use App\Http\Middleware\AuthenticateBioTimeClient;
use App\Http\Middleware\SetBioTimeLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['api', SetBioTimeLocale::class])->group(base_path('routes/biotime.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: ['iclock/*']);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateBioTimeClient::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isBioTimeRequest = fn (Request $request): bool => $request->is('api-token-auth', 'iclock/api/*');

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isBioTimeRequest) {
            if ($isBioTimeRequest($request)) {
                return response()->json($exception->errors(), 400);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isBioTimeRequest) {
            if ($isBioTimeRequest($request)) {
                $detail = match ($exception->getStatusCode()) {
                    404 => 'Not found.',
                    405 => sprintf('Method "%s" not allowed.', $request->method()),
                    default => $exception->getStatusCode() >= 500 ? 'Internal server error.' : 'Request failed.',
                };

                return response()->json(['detail' => $detail], $exception->getStatusCode(), $exception->getHeaders());
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $isBioTimeRequest($request) || $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
