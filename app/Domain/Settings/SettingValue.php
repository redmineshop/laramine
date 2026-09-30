<?php

namespace App\Domain\Settings;

use App\Models\Setting;

/**
 * Reads a Redmine setting row. A missing row uses the Redmine default.
 */
final class SettingValue
{
    public const DISPLAY_SUBPROJECTS_ISSUES = 'display_subprojects_issues';

    /**
     * Redmine `display_subprojects_issues` defaults to 1.
     */
    public function displaySubprojectsIssues(): bool
    {
        return $this->boolean(self::DISPLAY_SUBPROJECTS_ISSUES, true);
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
}
