<?php

namespace App\Domain\Auth;

/**
 * Why a sign-in did or did not open a session.
 *
 * Locked and registered accounts keep their own notices. Every other denial
 * uses the generic invalid-credentials notice.
 */
enum LoginDecision: string
{
    case Accepted = 'accepted';
    case Unknown = 'unknown';
    case Password = 'password';
    case Inactive = 'inactive';
    case Locked = 'locked';
    case Registered = 'registered';
    case NotAccount = 'not_account';
    case ExternalAuth = 'external_auth';
    case TwoFactor = 'two_factor';

    public function allowsSession(): bool
    {
        return match ($this) {
            self::Accepted => true,
            self::Unknown, self::Password, self::Inactive, self::Locked, self::Registered, self::NotAccount, self::ExternalAuth, self::TwoFactor => false,
        };
    }
}
