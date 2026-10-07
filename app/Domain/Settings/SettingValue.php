<?php

namespace App\Domain\Settings;

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
