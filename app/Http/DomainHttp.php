<?php

namespace App\Http;

use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Maps a domain failure onto the wiki and board HTTP status codes.
 *
 * A guest is sent to the sign-in page. A guest request that expects JSON
 * is 401. A signed-in user without the permission is 403.
 */
final class DomainHttp
{
    public function denied(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->guest(route('login'));
        }

        abort(403);
    }

    public function fail(Request $request, DomainException $exception): RedirectResponse|JsonResponse
    {
        if ($exception instanceof PermissionDeniedException) {
            return $this->denied($request);
        }
        if ($this->missing($exception)) {
            abort(404);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return back()->withErrors(['form' => $exception->getMessage()]);
    }

    public function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    public function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function nullableInt(Request $request, string $key): ?int
    {
        return $this->integer($request->input($key));
    }

    public function queryInt(Request $request, string $key): ?int
    {
        return $this->integer($request->query($key));
    }

    public function checked(Request $request, string $key): bool
    {
        $value = $request->input($key);

        return $value === 1 || $value === '1' || $value === true || $value === 'true' || $value === 'on';
    }

    public function redirectWanted(Request $request): bool
    {
        if (! $request->exists('redirect')) {
            return true;
        }

        return $this->checked($request, 'redirect');
    }

    private function missing(DomainException $exception): bool
    {
        return in_array($exception->getMessage(), [
            'Wiki page does not exist.',
            'Wiki section does not exist.',
            'Wiki version does not exist.',
            'Board does not exist.',
            'Message does not exist.',
        ], true);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
