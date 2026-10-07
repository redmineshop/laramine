<?php

namespace App\Domain\Auth;

/**
 * `settings.self_registration` values from the 7.0.1 authentication settings.
 */
enum SelfRegistrationMode: string
{
    case Disabled = '0';
    case Email = '1';
    case Manual = '2';
    case Automatic = '3';

    public static function fromSetting(string $value): self
    {
        return match ($value) {
            '0' => self::Disabled,
            '1' => self::Email,
            '2' => self::Manual,
            '3' => self::Automatic,
            default => self::Manual,
        };
    }
}
