<?php

namespace App\Domain\Auth;

/**
 * Redmine-shaped password digest stored in `hashed_password` and `salt`.
 *
 * The digest is the SHA-1 hex of the salt concatenated with the SHA-1 hex of
 * the clear password. New salts are 32 hex characters. The factory placeholder
 * is rejected and is not a credential.
 */
final class RedminePassword
{
    public const PLACEHOLDER_HASH = '0000000000000000000000000000000000000000';

    public function hash(string $clearPassword, string $salt): string
    {
        $inner = hash('sha1', $clearPassword);

        return hash('sha1', $salt.$inner);
    }

    public function verify(string $clearPassword, ?string $salt, string $stored): bool
    {
        if ($clearPassword === '') {
            return false;
        }

        $stored = strtolower($stored);
        if ($stored === '' || $stored === self::PLACEHOLDER_HASH || preg_match('/^[0-9a-f]{40}$/', $stored) !== 1) {
            return false;
        }

        return hash_equals($stored, $this->hash($clearPassword, $salt ?? ''));
    }

    public function generateSalt(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return array{salt: string, hashed_password: string}
     */
    public function seal(string $clearPassword): array
    {
        $salt = $this->generateSalt();

        return [
            'salt' => $salt,
            'hashed_password' => $this->hash($clearPassword, $salt),
        ];
    }
}
