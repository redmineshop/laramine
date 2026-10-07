<?php

namespace App\Http\Auth;

use App\Domain\Auth\AccountNotice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a signed-in account that must change its password to that form.
 */
final class PasswordChangeResponse
{
    public static function required(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->must_change_passwd === true;
    }

    public static function toForm(Request $request): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => AccountNotice::MUST_CHANGE,
                'must_change_passwd' => true,
            ], 403);
        }

        return Inertia::location(route('password.edit'));
    }
}
