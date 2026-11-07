<?php

use App\Http\Controllers\ReadinessController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/ready', ReadinessController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register Laravel's default web and stateless API middleware groups.
        // Do not call statefulApi(): bearer tokens authenticate API requests.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
