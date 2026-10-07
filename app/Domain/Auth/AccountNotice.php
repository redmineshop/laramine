<?php

namespace App\Domain\Auth;

use App\Domain\Settings\SettingValue;

/**
 * English notices for sign-in gates and Phase 2 account flows.
 *
 * Status notices follow the published 7.0.1 account wording. A wrong
 * password stays on the generic notice so the response does not reveal
 * which later gate would have failed.
 */
final class AccountNotice
{
    public const INVALID = 'Invalid user or password';

    public const LOCKED = 'Your account is locked.';

    public const PENDING = 'Your account was created and is now pending administrator approval.';

    public const NOT_ACTIVATED = 'Your account has not yet been activated.';

    public const MUST_CHANGE = 'You must change your password before you can continue.';

    public const PASSWORD_MUST_DIFFER = 'The new password must be different from the current password.';

    public const PASSWORD_UPDATED = 'Password was successfully updated.';

    public const CURRENT_PASSWORD = 'The current password is not valid.';

    public const RECOVERY_SENT = 'An email with instructions to choose a new password has been sent to you.';

    public const ACTIVATED = 'Your account has been activated. You can now log in.';

    public const REGISTERED_EMAIL = 'Account was successfully created. Use the activation link to activate it.';

    public function __construct(
        private readonly SettingValue $settings,
    ) {}

    public function signInFailure(LoginDecision $decision): string
    {
        return match ($decision) {
            LoginDecision::Locked => self::LOCKED,
            LoginDecision::Registered => $this->registeredFailure(),
            LoginDecision::Accepted,
            LoginDecision::Unknown,
            LoginDecision::Password,
            LoginDecision::Inactive,
            LoginDecision::NotAccount,
            LoginDecision::ExternalAuth,
            LoginDecision::TwoFactor => self::INVALID,
        };
    }

    private function registeredFailure(): string
    {
        if ($this->settings->selfRegistration() === SelfRegistrationMode::Email) {
            return self::NOT_ACTIVATED;
        }

        return self::PENDING;
    }
}
