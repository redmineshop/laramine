<?php

namespace App\Domain\Queries;

/**
 * Issue filter fields that are named in the catalog and not compiled yet.
 */
final class DeferredIssueFilters
{
    /**
     * @var list<string>
     */
    public const FIELDS = [
        'author.group',
        'author.role',
        'member_of_group',
        'assigned_to_role',
        'fixed_version.due_date',
        'fixed_version.status',
        'project.status',
        'subproject_id',
        'notes',
        'attachment',
        'attachment_description',
        'watcher_id',
        'updated_by',
        'last_updated_by',
        'spent_time',
        'any_searchable',
    ];

    public static function assert(string $field): void
    {
        if (in_array($field, self::FIELDS, true)) {
            throw new QueryValidationException('Filter field is deferred: '.$field.'.');
        }

        if (preg_match('/^cf_\d+\./', $field) === 1) {
            throw new QueryValidationException('Chained custom field filter is not supported: '.$field.'.');
        }
    }
}
