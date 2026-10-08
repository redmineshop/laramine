<?php

use App\Http\Middleware\EnforceSessionLifetime;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireTwoFactorEnrollment;
use App\Http\Middleware\ResumeAutologin;
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
        $middleware->web(append: [
            EnforceSessionLifetime::class,
            ResumeAutologin::class,
            EnsureActiveAccount::class,
            RequirePasswordChange::class,
            RequireTwoFactorEnrollment::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->encryptCookies(except: [
            'autologin',
        ]);
        $middleware->validateCsrfTokens(except: [
            'oauth/token',
            'oauth/revoke',
            '*.json',
            '*.xml',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
