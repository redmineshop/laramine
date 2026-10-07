<?php

namespace App\Domain\Settings;

use App\Domain\Auth\SelfRegistrationMode;
use App\Models\Setting;
use JsonException;

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

    public const TIMESPAN_FORMAT = 'timespan_format';

    public const TIMELOG_REQUIRED_FIELDS = 'timelog_required_fields';

    public const ATTACHMENT_MAX_SIZE = 'attachment_max_size';

    public const ATTACHMENT_EXTENSIONS_ALLOWED = 'attachment_extensions_allowed';

    public const ATTACHMENT_EXTENSIONS_DENIED = 'attachment_extensions_denied';

    public const NOTIFIED_EVENTS = 'notified_events';

    public const ACTIVITY_DAYS_DEFAULT = 'activity_days_default';

    public const APP_TITLE = 'app_title';

    public const MAIL_FROM = 'mail_from';

    public const BULK_DOWNLOAD_MAX_SIZE = 'bulk_download_max_size';

    public const START_OF_WEEK = 'start_of_week';

    public const NON_WORKING_WEEK_DAYS = 'non_working_week_days';

    public const GANTT_ITEMS_LIMIT = 'gantt_items_limit';

    public const GANTT_MONTHS_LIMIT = 'gantt_months_limit';

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
     * `minutes` renders `h:mm`. `decimal` renders two fractional digits. Anything else is `minutes`.
     */
    public function timespanFormat(): string
    {
        $stored = $this->string(self::TIMESPAN_FORMAT);
        if ($stored !== null && strtolower($stored) === 'decimal') {
            return 'decimal';
        }

        return 'minutes';
    }

    /**
     * Recognized timelog requirements are `issue_id` and `comments`.
     *
     * The stored value may be a JSON array, a comma-separated list, or a
     * simple YAML list of `- name` lines. Other tokens are ignored.
     *
     * @return list<string>
     */
    public function timelogRequiredFields(): array
    {
        $recognized = [];
        foreach ($this->stringList(self::TIMELOG_REQUIRED_FIELDS) as $name) {
            if (in_array($name, ['issue_id', 'comments'], true) && ! in_array($name, $recognized, true)) {
                $recognized[] = $name;
            }
        }

        return $recognized;
    }

    /**
     * Maximum attachment size in kilobytes. A missing or unreadable value is 5120.
     */
    public function attachmentMaxKilobytes(): int
    {
        $stored = $this->string(self::ATTACHMENT_MAX_SIZE);
        if ($stored !== null && preg_match('/^\d+$/', $stored) === 1) {
            return (int) $stored;
        }

        return 5120;
    }

    /**
     * @return list<string>
     */
    public function attachmentExtensionsAllowed(): array
    {
        return $this->extensions(self::ATTACHMENT_EXTENSIONS_ALLOWED);
    }

    /**
     * @return list<string>
     */
    public function attachmentExtensionsDenied(): array
    {
        return $this->extensions(self::ATTACHMENT_EXTENSIONS_DENIED);
    }

    /**
     * `bulk_download_max_size` is kilobytes. A missing or non-numeric value is 102400.
     */
    public function bulkDownloadMaxBytes(): int
    {
        $kilobytes = 102400;
        $stored = $this->string(self::BULK_DOWNLOAD_MAX_SIZE);
        if ($stored !== null && preg_match('/^\d+$/', $stored) === 1) {
            $kilobytes = (int) $stored;
        }

        return $kilobytes * 1024;
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

    /**
     * Days of activity shown when the request does not pass a count. Missing means 30.
     */
    public function activityDaysDefault(): int
    {
        return $this->integer(self::ACTIVITY_DAYS_DEFAULT, 30);
    }

    /**
     * Site name used in mail and feeds. Missing means Laramine.
     */
    public function appTitle(): string
    {
        return $this->string(self::APP_TITLE) ?? 'Laramine';
    }

    /**
     * Outbound From address. Empty when the row is missing.
     */
    public function mailFrom(): string
    {
        return $this->string(self::MAIL_FROM) ?? '';
    }

    /**
     * First weekday for the calendar. 1 is Monday, 6 is Saturday, 7 is Sunday.
     * A missing row, a blank value, or any other token uses Sunday, the English
     * first day of the week. Locale switching is not applied.
     */
    public function startOfWeek(): int
    {
        $stored = $this->string(self::START_OF_WEEK);
        if ($stored !== null && in_array($stored, ['1', '6', '7'], true)) {
            return (int) $stored;
        }

        return 7;
    }

    /**
     * Weekdays with no work, 1 Monday through 7 Sunday. A missing row is Saturday and Sunday.
     * An explicit empty list means every day is a working day.
     *
     * @return list<int>
     */
    public function nonWorkingWeekDays(): array
    {
        $stored = Setting::query()->where('name', self::NON_WORKING_WEEK_DAYS)->value('value');
        if (! is_string($stored)) {
            return [6, 7];
        }

        $days = [];
        foreach ($this->stringList(self::NON_WORKING_WEEK_DAYS) as $token) {
            if (preg_match('/^[1-7]$/', $token) !== 1) {
                continue;
            }
            $day = (int) $token;
            if (! in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * How many gantt rows to draw. A missing row is 500. A blank value is unlimited.
     * A non-numeric token is 0.
     */
    public function ganttItemsLimit(): ?int
    {
        $stored = Setting::query()->where('name', self::GANTT_ITEMS_LIMIT)->value('value');
        if (! is_string($stored)) {
            return 500;
        }
        if (trim($stored) === '') {
            return null;
        }
        if (preg_match('/^\d+$/', trim($stored)) === 1) {
            return (int) trim($stored);
        }

        return 0;
    }

    /**
     * Largest month count the gantt will accept from the request or the preference.
     * A missing or non-numeric row is 24.
     */
    public function ganttMonthsLimit(): int
    {
        return $this->integer(self::GANTT_MONTHS_LIMIT, 24);
    }

    /**
     * JSON array of strings. Null when the row is missing or not a JSON array.
     *
     * @return list<string>|null
     */
    public function jsonStringList(string $name): ?array
    {
        $stored = Setting::query()->where('name', $name)->value('value');
        if (! is_string($stored)) {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $values = [];
        foreach ($decoded as $item) {
            if (! is_string($item)) {
                return null;
            }
            $values[] = $item;
        }

        return $values;
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

    /**
     * @return list<string>
     */
    private function stringList(string $name): array
    {
        $stored = Setting::query()->where('name', $name)->value('value');
        if (! is_string($stored)) {
            return [];
        }
        $trimmed = trim($stored);
        if ($trimmed === '') {
            return [];
        }
        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (! is_array($decoded)) {
                return [];
            }
            $items = [];
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = trim($item);
                }
            }

            return $items;
        }
        if (str_starts_with($trimmed, '---') || str_contains($trimmed, "\n")) {
            $items = [];
            foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
                if (preg_match('/^\s*-\s+(.+)$/', $line, $match) === 1) {
                    $token = trim($match[1], " \t\"'");
                    if ($token !== '') {
                        $items[] = $token;
                    }
                }
            }

            return $items;
        }

        $items = [];
        foreach (explode(',', $trimmed) as $part) {
            $token = trim($part);
            if ($token !== '') {
                $items[] = $token;
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function extensions(string $name): array
    {
        $extensions = [];
        foreach ($this->stringList($name) as $token) {
            $extension = strtolower(ltrim($token, '.'));
            if ($extension !== '' && ! in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }
}
