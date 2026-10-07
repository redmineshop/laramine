<?php

namespace App\Http\Middleware;

use App\Http\Auth\PasswordChangeResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the rest of the app until `must_change_passwd` is cleared.
 *
 * The password form and sign-out stay available. The session itself stays open.
 */
class RequirePasswordChange
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! PasswordChangeResponse::required($request)) {
            return $next($request);
        }

        if ($request->routeIs('password.edit', 'password.update', 'logout')) {
            return $next($request);
        }

        return PasswordChangeResponse::toForm($request);
    }
}
