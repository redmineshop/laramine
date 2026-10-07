<?php

namespace App\Http\Middleware;

use App\Domain\Auth\WebSession;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops a session whose account can no longer stay signed in.
 */
class EnsureActiveAccount
{
    public function __construct(
        private readonly WebSession $sessions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->canKeepWebSession()) {
            return $this->sessions->drop($request);
        }

        return $next($request);
    }
}
