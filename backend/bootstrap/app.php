<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie-based) auth for the /api group — see
        // DECISIONS.md "Auth & users". Requires SANCTUM_STATEFUL_DOMAINS
        // in .env to list the frontend's origin(s).
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // v2 §2/§9: "Backend returns JSON only" — there is no web login
        // page to redirect to. Without this, Laravel's default auth
        // middleware only returns JSON when the request's Accept header
        // says so; anything else (a bare curl, a bot, visiting the URL
        // directly) hits `route('login')`, which doesn't exist, and
        // crashes into a 500 instead of a clean 401. Found live in
        // production — see DECISIONS.md.
        $exceptions->render(function (AuthenticationException $e, $request): JsonResponse {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
