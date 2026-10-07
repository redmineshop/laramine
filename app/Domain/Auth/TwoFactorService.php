<?php

namespace App\Domain\Auth;

use App\Domain\DomainException;
use App\Models\Token;
use App\Models\User;

/**
 * TOTP enrollment, verification, and `twofa_backup_code` tokens.
 */
final class TwoFactorService
{
    public const BACKUP_CODES = 10;

    public function __construct(
        private readonly Totp $totp,
        private readonly TwoFactorPolicy $policy,
        private readonly ActionToken $tokens,
    ) {}

    public function beginSecret(): string
    {
        if ($this->policy->mode() === TwoFactorPolicy::DISABLED) {
            throw new DomainException('Two-factor authentication is disabled.');
        }

        return $this->totp->generateSecret();
    }

    /**
     * @return list<string>
     */
    public function confirm(User $user, string $secret, string $code, int $now): array
    {
        if ($this->policy->mode() === TwoFactorPolicy::DISABLED) {
            throw new DomainException('Two-factor authentication is disabled.');
        }

        $used = $this->totp->verify($secret, trim($code), $now, null);
        if ($used === null) {
            throw new AccountValidationException([
                'code' => ['The code is invalid.'],
            ]);
        }

        $user->forceFill([
            'twofa_scheme' => 'totp',
            'twofa_totp_key' => $secret,
            'twofa_totp_last_used_at' => $used,
        ])->save();

        return $this->tokens->replaceBackupCodes($user, self::BACKUP_CODES);
    }

    public function verify(User $user, string $code, int $now): bool
    {
        $secret = $user->twofa_totp_key;
        if (! is_string($secret) || $secret === '' || $user->twofa_scheme !== 'totp') {
            return false;
        }

        $last = is_numeric($user->twofa_totp_last_used_at) ? (int) $user->twofa_totp_last_used_at : null;
        $used = $this->totp->verify($secret, trim($code), $now, $last);
        if ($used === null) {
            return false;
        }

        $user->forceFill(['twofa_totp_last_used_at' => $used])->save();

        return true;
    }

    public function verifyBackup(User $user, string $code): bool
    {
        $token = $this->tokens->findByValue(Token::ACTION_TWOFA_BACKUP, strtolower(trim($code)));
        if (! $token instanceof Token || (int) $token->user_id !== (int) $user->id) {
            return false;
        }

        $token->delete();

        return true;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'twofa_scheme' => null,
            'twofa_totp_key' => null,
            'twofa_totp_last_used_at' => null,
        ])->save();
        Token::query()
            ->where('user_id', $user->id)
            ->where('action', Token::ACTION_TWOFA_BACKUP)
            ->delete();
    }
}
