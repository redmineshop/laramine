<?php

namespace App\Http\Middleware;

use App\Domain\Auth\TwoFactorPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an already signed-in account to enrollment when the setting or the user flag requires a scheme.
 */
class RequireTwoFactorEnrollment
{
    public function __construct(
        private readonly TwoFactorPolicy $policy,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->policy->mustEnroll($user)) {
            return $next($request);
        }

        if ($request->routeIs('twofa.edit', 'twofa.confirm', 'twofa.destroy', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Two-factor authentication must be enabled.'], 403);
        }

        return redirect()->route('twofa.edit');
    }
}
