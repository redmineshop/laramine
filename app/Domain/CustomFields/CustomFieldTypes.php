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

    public const DOCUMENT = 'Document';

    public const ISSUE_PRIORITY = 'IssuePriority';

    public const TIME_ENTRY_ACTIVITY = 'TimeEntryActivity';

    public const DOCUMENT_CATEGORY = 'DocumentCategory';

    /**
     * Types this engine can read and write.
     *
     * Document values use `customized_type` Document. The `documents` table
     * stays unmigrated. Enumeration hosts are rows in `enumerations`.
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
        'DocumentCustomField' => self::DOCUMENT,
        'IssuePriorityCustomField' => self::ISSUE_PRIORITY,
        'TimeEntryActivityCustomField' => self::TIME_ENTRY_ACTIVITY,
        'DocumentCategoryCustomField' => self::DOCUMENT_CATEGORY,
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
