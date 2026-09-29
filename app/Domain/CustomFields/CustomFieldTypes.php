<?php

namespace App\Domain\CustomFields;

/**
 * STI `custom_fields.type` values and the `custom_values.customized_type` they write.
 */
final class CustomFieldTypes
{
    public const ISSUE = 'Issue';

    public const PROJECT = 'Project';

    public const USER = 'User';

    public const GROUP = 'Group';

    public const TIME_ENTRY = 'TimeEntry';

    public const VERSION = 'Version';

    /**
     * Types this engine can read and write. Exotic enumeration STI fields are omitted.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'IssueCustomField' => self::ISSUE,
        'ProjectCustomField' => self::PROJECT,
        'UserCustomField' => self::USER,
        'GroupCustomField' => self::GROUP,
        'TimeEntryCustomField' => self::TIME_ENTRY,
        'VersionCustomField' => self::VERSION,
    ];

    public static function customizedType(string $sti): ?string
    {
        return self::SUPPORTED[$sti] ?? null;
    }

    public static function stiFor(string $customizedType): ?string
    {
        $sti = array_search($customizedType, self::SUPPORTED, true);

        return is_string($sti) ? $sti : null;
    }
}
