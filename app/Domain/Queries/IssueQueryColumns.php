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
     * Built-in names the projection can fill.
     *
     * `total_estimated_hours` sums visible descendant estimates. `total_spent_hours`
     * is omitted unless the actor can view time entries.
     * `estimated_remaining_hours` is the issue's own estimate times the undone ratio.
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
        'total_estimated_hours',
        'spent_hours',
        'total_spent_hours',
        'estimated_remaining_hours',
        'done_ratio',
        'created_on',
        'closed_on',
        'is_private',
        'description',
    ];

    /**
     * Null estimate and null done ratio count as zero. The product is the remaining time.
     */
    public const REMAINING_SQL = 'COALESCE(issues.estimated_hours, 0) * (100 - COALESCE(issues.done_ratio, 0)) / 100';
}
