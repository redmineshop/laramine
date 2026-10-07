<?php

namespace App\Http\Middleware;

use App\Domain\Auth\WebSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies `session_lifetime` and `session_timeout` to a session that has a token row.
 *
 * A session without that key, such as a test acting as a user, is left alone.
 * An expired session keeps the autologin cookie so a later resume can open a new one.
 */
class EnforceSessionLifetime
{
    public function __construct(
        private readonly WebSession $sessions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof User && $this->sessions->touch($request) === false) {
            $this->sessions->clearExpired($request);
        }

        return $next($request);
    }
}
