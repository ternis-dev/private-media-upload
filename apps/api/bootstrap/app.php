<?php

use App\Exceptions\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pwf.rate' => \App\Http\Middleware\ApiRateLimit::class,
            'pwf.bearer' => \App\Http\Middleware\OptionalBearer::class,
            'pwf.auth' => \App\Http\Middleware\RequireBearer::class,
        ]);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (ApiError $e) => response()->json($e->payload, $e->getStatusCode(), $e->getHeaders()));
    })->create();
