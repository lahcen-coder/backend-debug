<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // This is a pure token-based (Bearer) API — NOT cookie/CSRF based.
        // Do NOT add EnsureFrontendRequestsAreStateful here, it would force
        // stateful CSRF checks on the frontend origin and break register/login (419).
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
