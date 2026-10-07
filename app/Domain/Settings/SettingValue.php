<?php

namespace App\Domain\Settings;

use App\Domain\Auth\SelfRegistrationMode;
use App\Models\Setting;

/**
 * Reads a Redmine setting row. A missing row uses the Redmine default.
 */
final class SettingValue
{
    public const DISPLAY_SUBPROJECTS_ISSUES = 'display_subprojects_issues';

    public const PROTOCOL = 'protocol';

    public const HOST_NAME = 'host_name';

    public const THUMBNAILS_ENABLED = 'thumbnails_enabled';

    public const THUMBNAILS_SIZE = 'thumbnails_size';

    public const SELF_REGISTRATION = 'self_registration';

    public const LOST_PASSWORD = 'lost_password';

    public const PASSWORD_MIN_LENGTH = 'password_min_length';

    public const PASSWORD_REQUIRED_CHAR_CLASSES = 'password_required_char_classes';

    public const DEFAULT_NOTIFICATION_OPTION = 'default_notification_option';

    public const TWOFA = 'twofa';

    public const REST_API_ENABLED = 'rest_api_enabled';

    public const AUTOLOGIN = 'autologin';

    public const SESSION_LIFETIME = 'session_lifetime';

    public const SESSION_TIMEOUT = 'session_timeout';

    /**
     * Redmine `display_subprojects_issues` defaults to 1.
     */
    public function displaySubprojectsIssues(): bool
    {
        return $this->boolean(self::DISPLAY_SUBPROJECTS_ISSUES, true);
    }

    /**
     * `http` or `https`. A missing or other value is `http`.
     */
    public function protocol(): string
    {
        $stored = $this->string(self::PROTOCOL);
        if ($stored !== null && strtolower($stored) === 'https') {
            return 'https';
        }

        return 'http';
    }

    /**
     * Host and optional port. A missing row is an empty string.
     */
    public function hostName(): string
    {
        return $this->string(self::HOST_NAME) ?? '';
    }

    /**
     * Redmine `thumbnails_enabled` defaults to off.
     */
    public function thumbnailsEnabled(): bool
    {
        return $this->boolean(self::THUMBNAILS_ENABLED, false);
    }

    /**
     * Redmine `thumbnails_size` defaults to 100.
     */
    public function thumbnailsSize(): int
    {
        $stored = $this->string(self::THUMBNAILS_SIZE);
        if ($stored !== null && preg_match('/^[1-9]\d*$/', $stored) === 1) {
            return (int) $stored;
        }

        return 100;
    }

    /**
     * Missing or unrecognized values use manual activation (`2`).
     */
    public function selfRegistration(): SelfRegistrationMode
    {
        $stored = $this->string(self::SELF_REGISTRATION);
        if ($stored === null) {
            return SelfRegistrationMode::Manual;
        }

        return SelfRegistrationMode::fromSetting($stored);
    }

    /**
     * Lost password defaults to on.
     */
    public function lostPasswordEnabled(): bool
    {
        return $this->boolean(self::LOST_PASSWORD, true);
    }

    /**
     * A missing or non-numeric value is 8. Zero is treated as 1 so a blank password is rejected.
     */
    public function passwordMinLength(): int
    {
        $stored = $this->string(self::PASSWORD_MIN_LENGTH);
        if ($stored !== null && preg_match('/^\d+$/', $stored) === 1) {
            $value = (int) $stored;

            return $value < 1 ? 1 : $value;
        }

        return 8;
    }

    /**
     * How many of the four character classes a new password must include. Missing means none.
     */
    public function passwordRequiredCharClasses(): int
    {
        $stored = $this->string(self::PASSWORD_REQUIRED_CHAR_CLASSES);
        if ($stored !== null && preg_match('/^\d+$/', $stored) === 1) {
            return min(4, (int) $stored);
        }

        return 0;
    }

    /**
     * Mail notification stored on a newly registered account. Missing means `only_my_events`.
     */
    public function defaultMailNotification(): string
    {
        $stored = $this->string(self::DEFAULT_NOTIFICATION_OPTION);
        $legal = ['all', 'selected', 'only_my_events', 'only_assigned', 'only_owner', 'none'];
        if ($stored !== null && in_array($stored, $legal, true)) {
            return $stored;
        }

        return 'only_my_events';
    }

    /**
     * `0` disabled, `1` optional, `2` required for administrators, `3` required for every account.
     * Anything else is disabled.
     */
    public function twoFactorMode(): int
    {
        $value = $this->integer(self::TWOFA, 0);

        return in_array($value, [0, 1, 2, 3], true) ? $value : 0;
    }

    /**
     * REST API authentication defaults to off.
     */
    public function restApiEnabled(): bool
    {
        return $this->boolean(self::REST_API_ENABLED, false);
    }

    /**
     * Remember-me lifetime in days. Only 0, 1, 7, 30, and 365 are accepted.
     */
    public function autologinDays(): int
    {
        $value = $this->integer(self::AUTOLOGIN, 0);

        return in_array($value, [0, 1, 7, 30, 365], true) ? $value : 0;
    }

    /**
     * Maximum session age in minutes. Zero disables the cap.
     */
    public function sessionLifetimeMinutes(): int
    {
        return $this->integer(self::SESSION_LIFETIME, 0);
    }

    /**
     * Idle session timeout in minutes. Zero disables the cap.
     */
    public function sessionTimeoutMinutes(): int
    {
        return $this->integer(self::SESSION_TIMEOUT, 0);
    }

    private function integer(string $name, int $default): int
    {
        $stored = $this->string($name);
        if ($stored !== null && preg_match('/^\d+$/', $stored) === 1) {
            return (int) $stored;
        }

        return $default;
    }

    public function boolean(string $name, bool $default): bool
    {
        $stored = Setting::query()->where('name', $name)->value('value');
        if (! is_string($stored)) {
            return $default;
        }

        $normalized = strtolower(trim($stored));
        if ($normalized === '') {
            return $default;
        }

        return ! in_array($normalized, ['0', 'false'], true);
    }

    private function string(string $name): ?string
    {
        $stored = Setting::query()->where('name', $name)->value('value');
        if (! is_string($stored)) {
            return null;
        }
        $trimmed = trim($stored);

        return $trimmed === '' ? null : $trimmed;
    }
}
