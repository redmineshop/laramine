<?php

namespace App\Domain\Auth;

use App\Domain\Settings\SettingValue;
use App\Models\Token;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel session guard plus the Redmine `session` and `autologin` token rows.
 *
 * The session row stays in `sessions`. The token row can revoke that session.
 * Autologin is a cookie holding an `autologin` token, not a remember column.
 */
final class WebSession
{
    public const TOKEN_KEY = 'session_token';

    public const STARTED_KEY = 'session_started_at';

    public const TOUCHED_KEY = 'session_touched_at';

    public const AUTOLOGIN_COOKIE = 'autologin';

    public const TWOFA_USER_KEY = 'twofa_user_id';

    public const TWOFA_REMEMBER_KEY = 'twofa_remember';

    public function __construct(
        private readonly ActionToken $tokens,
        private readonly SettingValue $settings,
    ) {}

    public function open(Request $request, User $user, bool $remember): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('registered_user_id');
        $request->session()->forget(self::TWOFA_USER_KEY);
        $request->session()->forget(self::TWOFA_REMEMBER_KEY);

        $token = $this->tokens->issueNamed($user, Token::ACTION_SESSION);
        $now = now()->getTimestamp();
        $request->session()->put(self::TOKEN_KEY, $token->value);
        $request->session()->put(self::STARTED_KEY, $now);
        $request->session()->put(self::TOUCHED_KEY, $now);

        if ($remember && $this->settings->autologinDays() > 0) {
            $autologin = $this->tokens->issueNamed($user, Token::ACTION_AUTOLOGIN);
            Cookie::queue(Cookie::make(
                self::AUTOLOGIN_COOKIE,
                $autologin->value,
                $this->settings->autologinDays() * 24 * 60,
                '/',
                null,
                null,
                true,
                false,
                'lax',
            ));
        }
    }

    public function logout(Request $request): void
    {
        $this->deleteSessionToken($request);
        $this->consumeAutologinCookie($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Ends the web session and keeps a still-valid autologin cookie.
     */
    public function clearExpired(Request $request): void
    {
        $this->deleteSessionToken($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Ends the web session and keeps a still-valid autologin cookie.
     */
    public function expire(Request $request): Response
    {
        $this->clearExpired($request);

        return $this->unauthenticated($request);
    }

    public function drop(Request $request): Response
    {
        $this->consumeAutologinCookie($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->unauthenticated($request);
    }

    public function resumeAutologin(Request $request): bool
    {
        if ($request->user() instanceof User) {
            return false;
        }

        $value = $request->cookie(self::AUTOLOGIN_COOKIE);
        if (! is_string($value) || $value === '') {
            return false;
        }

        $days = $this->settings->autologinDays();
        $token = $this->tokens->findByValue(Token::ACTION_AUTOLOGIN, $value);
        $user = $token?->user;
        if (! $token instanceof Token || ! $user instanceof User || $days < 1 || ! $user->canKeepWebSession()) {
            $this->consumeAutologinCookie($request);

            return false;
        }

        if ($token->created_on->lessThanOrEqualTo(now()->subDays($days))) {
            $token->delete();
            Cookie::queue(Cookie::forget(self::AUTOLOGIN_COOKIE));

            return false;
        }

        $this->open($request, $user, false);

        return true;
    }

    /**
     * Null when this session has no token key (for example a test actingAs).
     * False when the key is present but the row or the lifetime check failed.
     */
    public function touch(Request $request): ?bool
    {
        $value = $request->session()->get(self::TOKEN_KEY);
        if (! is_string($value) || $value === '') {
            return null;
        }

        $token = $this->tokens->findByValue(Token::ACTION_SESSION, $value);
        $user = $request->user();
        if (! $token instanceof Token || ! $user instanceof User || (int) $token->user_id !== (int) $user->id) {
            return false;
        }

        $now = now()->getTimestamp();
        $started = $request->session()->get(self::STARTED_KEY);
        $touched = $request->session()->get(self::TOUCHED_KEY);
        $startedAt = is_int($started) ? $started : (is_numeric($started) ? (int) $started : $now);
        $touchedAt = is_int($touched) ? $touched : (is_numeric($touched) ? (int) $touched : $now);
        $lifetime = $this->settings->sessionLifetimeMinutes();
        $timeout = $this->settings->sessionTimeoutMinutes();
        if ($lifetime > 0 && ($now - $startedAt) > ($lifetime * 60)) {
            return false;
        }
        if ($timeout > 0 && ($now - $touchedAt) > ($timeout * 60)) {
            return false;
        }

        $request->session()->put(self::TOUCHED_KEY, $now);
        $token->forceFill(['updated_on' => now()])->save();

        return true;
    }

    public function holdForTwoFactor(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::TWOFA_USER_KEY, $user->id);
        $request->session()->put(self::TWOFA_REMEMBER_KEY, $remember);
    }

    public function pendingTwoFactor(Request $request): ?User
    {
        $id = $request->session()->get(self::TWOFA_USER_KEY);
        if (! is_numeric($id)) {
            return null;
        }

        $user = User::query()->find((int) $id);

        return $user instanceof User ? $user : null;
    }

    public function pendingRemember(Request $request): bool
    {
        return $request->session()->get(self::TWOFA_REMEMBER_KEY) === true;
    }

    private function deleteSessionToken(Request $request): void
    {
        $value = $request->session()->get(self::TOKEN_KEY);
        if (! is_string($value) || $value === '') {
            return;
        }

        $token = $this->tokens->findByValue(Token::ACTION_SESSION, $value);
        $token?->delete();
    }

    private function consumeAutologinCookie(Request $request): void
    {
        $value = $request->cookie(self::AUTOLOGIN_COOKIE);
        if (is_string($value) && $value !== '') {
            $this->tokens->findByValue(Token::ACTION_AUTOLOGIN, $value)?->delete();
        }
        Cookie::queue(Cookie::forget(self::AUTOLOGIN_COOKIE));
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login');
    }
}
