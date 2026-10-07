<?php

namespace App\Domain\Auth;

/**
 * Why a sign-in did or did not open a session.
 *
 * HTTP responses collapse every denial into one message.
 */
enum LoginDecision: string
{
    case Accepted = 'accepted';
    case Unknown = 'unknown';
    case Password = 'password';
    case Inactive = 'inactive';
    case NotAccount = 'not_account';
    case ExternalAuth = 'external_auth';
    case TwoFactor = 'two_factor';

    public function allowsSession(): bool
    {
        return match ($this) {
            self::Accepted => true,
            self::Unknown, self::Password, self::Inactive, self::NotAccount, self::ExternalAuth, self::TwoFactor => false,
        };
    }
}
