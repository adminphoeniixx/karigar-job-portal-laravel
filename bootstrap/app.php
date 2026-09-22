<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureKycEnabled;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app never faces the internet directly: easypanel's proxy
        // terminates TLS and forwards plain HTTP with X-Forwarded-Proto. Until
        // this was here Laravel believed the scheme was the one it saw, so
        // every generated URL came out `http://` on an `https://` page —
        // including the Vite asset tags. Each of the ~48 assets then had to
        // bounce through a 301, enough of them came back 503, the JS bundle
        // never loaded, and Inertia mounted nothing. The whole panel rendered
        // as a blank cream page with no error in the console.
        //
        // `at: '*'` because the proxy's address is assigned by the platform
        // and changes; nothing can reach the container except through it.
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            SetLocale::class,
            EnsureActiveAccount::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'subscription' => EnsureActiveSubscription::class,
            'kyc.enabled' => EnsureKycEnabled::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'razorpay/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
