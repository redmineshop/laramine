<?php

namespace App\Domain\Queries;

/**
 * Display columns for an issue query.
 *
 * A null `column_names` value means {@see self::DEFAULT}. `present` keeps the
 * names from {@see self::AVAILABLE}, plus a visible `cf_{id}`. Unknown names
 * stay stored and are left out of the projection.
 */
final class IssueQueryColumns
{
    /**
     * @var list<string>
     */
    public const DEFAULT = [
        'tracker',
        'status',
        'priority',
        'subject',
        'assigned_to',
        'updated_on',
    ];

    /**
     * Built-in names the projection can fill. Descendant hour columns are absent.
     *
     * @var list<string>
     */
    public const AVAILABLE = [
        'id',
        'project',
        'tracker',
        'parent',
        'status',
        'priority',
        'subject',
        'author',
        'assigned_to',
        'updated_on',
        'category',
        'fixed_version',
        'start_date',
        'due_date',
        'estimated_hours',
        'spent_hours',
        'done_ratio',
        'created_on',
        'closed_on',
        'is_private',
        'description',
    ];
}
