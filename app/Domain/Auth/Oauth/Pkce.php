<?php

namespace App\Domain\Auth\Oauth;

/**
 * PKCE S256 and plain, the challenge methods stored on `oauth_access_grants`.
 */
final class Pkce
{
    public static function s256(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function verify(?string $method, ?string $challenge, ?string $verifier): bool
    {
        if ($method === null || $method === '' || $challenge === null || $challenge === '') {
            return $verifier === null || $verifier === '';
        }
        if (! is_string($verifier) || preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) !== 1) {
            return false;
        }
        $expected = match ($method) {
            'S256' => self::s256($verifier),
            'plain' => $verifier,
            default => null,
        };
        if ($expected === null || strlen($expected) !== strlen($challenge)) {
            return false;
        }

        return hash_equals($expected, $challenge);
    }
}
