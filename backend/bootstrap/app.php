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

        // v2 §2/§9: "Backend returns JSON only" — there is no web login
        // page. ApplicationBuilder::withMiddleware() registers
        // `redirectGuestsTo(fn () => route('login'))` as a framework
        // DEFAULT before this closure ever runs; without overriding it,
        // an unauthenticated request that doesn't explicitly ask for
        // JSON (a bare curl, a bot, visiting the URL directly) calls
        // route('login') while still *constructing* the
        // AuthenticationException — i.e. before it's even thrown — and
        // that crashes into an uncaught RouteNotFoundException (500),
        // bypassing any exception render() callback entirely. Found live
        // in production — see DECISIONS.md.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Belt-and-suspenders alongside redirectGuestsTo() above: even
        // with that fix, Laravel's default unauthenticated() handler
        // still falls back to `?? route('login')` if shouldReturnJson()
        // is ever false, so force JSON unconditionally here rather than
        // depend on that check.
        $exceptions->render(function (AuthenticationException $e, $request): JsonResponse {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        });
    })->create();
