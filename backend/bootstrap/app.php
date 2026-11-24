<?php

use App\Http\Middleware\EnsureAccountIsEnabled;
use App\Http\Middleware\EnsureProfileIsComplete;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api/v1',
        'middleware' => ['api', 'auth:sanctum', 'account.enabled', 'profile.complete'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(
            static fn (): ?string => null
        );
        $middleware->alias([
            'profile.complete' => EnsureProfileIsComplete::class,
            'account.enabled' => EnsureAccountIsEnabled::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureAccountIsEnabled::class);
        $middleware->trimStrings(except: ['current_password']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/v1/*') || $request->expectsJson()
        );
    })->create();
