<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sort and group keys. Grouping orders rows; it does not collapse them.
 *
 * Keys such as `priority` order by the issue foreign key, not by enumeration position.
 */
final class IssueQuerySort
{
    /**
     * @var array<string, string>
     */
    private const COLUMNS = [
        'id' => 'id',
        'project' => 'project_id',
        'project_id' => 'project_id',
        'tracker' => 'tracker_id',
        'tracker_id' => 'tracker_id',
        'status' => 'status_id',
        'status_id' => 'status_id',
        'priority' => 'priority_id',
        'priority_id' => 'priority_id',
        'subject' => 'subject',
        'author' => 'author_id',
        'author_id' => 'author_id',
        'assigned_to' => 'assigned_to_id',
        'assigned_to_id' => 'assigned_to_id',
        'updated_on' => 'updated_on',
        'created_on' => 'created_on',
        'start_date' => 'start_date',
        'due_date' => 'due_date',
        'done_ratio' => 'done_ratio',
        'estimated_hours' => 'estimated_hours',
        'category' => 'category_id',
        'category_id' => 'category_id',
        'fixed_version' => 'fixed_version_id',
        'fixed_version_id' => 'fixed_version_id',
        'parent' => 'parent_id',
        'parent_id' => 'parent_id',
        'is_private' => 'is_private',
        'closed_on' => 'closed_on',
        'description' => 'description',
    ];

    /**
     * @param  Builder<Issue>  $query
     * @param  list<array{0: string, 1: string}>  $sort
     */
    public function apply(Builder $query, ?string $groupBy, array $sort): void
    {
        if ($groupBy !== null && $groupBy !== '') {
            $query->orderBy($this->column($groupBy), 'asc');
        }

        if ($sort === []) {
            $query->orderBy('issues.id');

            return;
        }

        foreach ($sort as [$name, $direction]) {
            $query->orderBy($this->column($name), $direction);
        }
    }

    public function column(string $name): string
    {
        $column = self::COLUMNS[$name] ?? null;
        if ($column === null) {
            throw new QueryValidationException('Sort column is not available: '.$name.'.');
        }

        return 'issues.'.$column;
    }
}
