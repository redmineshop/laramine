<?php

namespace App\Domain\Auth;

use App\Domain\Settings\SettingValue;
use App\Models\User;

/**
 * Setting `twofa`: 0 disabled, 1 optional, 2 required for administrators, 3 required.
 *
 * `users.twofa_required` forces enrollment for that account when the setting is not disabled.
 * A stored scheme is ignored while the setting is disabled.
 */
final class TwoFactorPolicy
{
    public const DISABLED = 0;

    public const OPTIONAL = 1;

    public const REQUIRED_ADMINS = 2;

    public const REQUIRED_ALL = 3;

    public function __construct(
        private readonly SettingValue $settings,
    ) {}

    public function mode(): int
    {
        return $this->settings->twoFactorMode();
    }

    public function schemeEnabled(User $user): bool
    {
        return $this->mode() !== self::DISABLED
            && $user->twofa_scheme === 'totp'
            && is_string($user->twofa_totp_key)
            && $user->twofa_totp_key !== '';
    }

    public function mustEnroll(User $user): bool
    {
        if ($this->mode() === self::DISABLED || $this->schemeEnabled($user)) {
            return false;
        }
        if ($user->twofa_required === true) {
            return true;
        }

        return match ($this->mode()) {
            self::REQUIRED_ALL => true,
            self::REQUIRED_ADMINS => $user->admin === true,
            default => false,
        };
    }

    public function challengeRequired(User $user): bool
    {
        return $this->mustEnroll($user) || $this->schemeEnabled($user);
    }
}
