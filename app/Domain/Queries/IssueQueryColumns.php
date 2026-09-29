<?php

namespace App\Domain\Queries;

/**
 * Display columns for an issue query.
 *
 * A null `column_names` value means this default list. The runner still loads
 * full issue rows; the list is what a later UI would show.
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
}
