<?php

namespace App\Http\Middleware;

use App\Domain\Auth\WebSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a session from a still-valid `autologin` cookie when the request is a guest.
 */
class ResumeAutologin
{
    public function __construct(
        private readonly WebSession $sessions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->resumeAutologin($request);

        return $next($request);
    }
}
