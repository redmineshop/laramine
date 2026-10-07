<?php

namespace App\Domain\Queries;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\PermissionDeniedException;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Query;
use App\Models\TimeEntry;
use App\Models\User;
use DateTimeInterface;

/**
 * Runs `ProjectQuery` and `ProjectAdminQuery`.
 *
 * A project query sees active and closed projects the actor can see.
 * Archived projects and projects scheduled for deletion stay out, including
 * for an administrator. An admin query is the opposite scope: every status,
 * and only an active administrator can run it. There are no total columns.
 * `is_public` is the groupable column. A blank sort finishes on `lft`.
 */
final class ProjectQueryRunner
{
    /**
     * @var list<string>
     */
    public const COLUMNS = [
        'name',
        'status',
        'short_description',
        'homepage',
        'identifier',
        'parent_id',
        'is_public',
        'created_on',
        'updated_on',
        'last_activity_date',
    ];

    /**
     * @var list<string>
     */
    public const DEFAULT_COLUMNS = [
        'name',
        'identifier',
        'short_description',
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $issues,
        private readonly TimeEntryVisibility $timeEntries,
        private readonly SavedQueryService $saved,
    ) {}

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<string>|null  $columns
     * @param  list<array{0: string, 1: string}>  $sort
     * @return array{
     *     ids: list<int>,
     *     columns: list<string>,
     *     rows: list<array<string, string|null>>,
     *     groups: list<array{value: string, count: int}>
     * }
     */
    public function run(?User $actor, string $type, array $filters, ?array $columns = null, array $sort = [], ?string $groupBy = null): array
    {
        $admin = $type === QueryType::PROJECT_ADMIN;
        if ($type !== QueryType::PROJECT && ! $admin) {
            throw new QueryValidationException('Only a project query can be executed.');
        }
        if ($admin && ! ($actor !== null && $actor->admin && $actor->isActive())) {
            throw new PermissionDeniedException('view_project');
        }

        $parsed = QueryFilter::listFromMap($filters);
        foreach ($parsed as $filter) {
            $this->assertFilter($filter, $admin);
        }
        $names = $this->columns($columns);
        $group = $groupBy === null || $groupBy === '' ? null : $groupBy;
        if ($group !== null && $group !== 'is_public') {
            throw new QueryValidationException('Group column is not available: '.$group.'.');
        }
        foreach ($sort as [$column, $direction]) {
            $this->assertSort($column, $direction);
        }

        $projects = [];
        foreach (Project::query()->orderBy('lft')->get() as $project) {
            if ($this->included($actor, $project, $admin) && $this->matches($actor, $project, $parsed)) {
                $projects[] = $project;
            }
        }
        $activity = [];
        foreach ($projects as $project) {
            $activity[(int) $project->id] = $this->lastActivity($actor, $project);
        }
        $this->sortProjects($projects, $sort, $group, $activity);

        $rows = [];
        $ids = [];
        foreach ($projects as $project) {
            $ids[] = (int) $project->id;
            $cells = [];
            foreach ($names as $name) {
                $cells[$name] = $this->cell($project, $name, $activity[(int) $project->id]);
            }
            $rows[] = $cells;
        }

        return [
            'ids' => $ids,
            'columns' => $names,
            'rows' => $rows,
            'groups' => $group === null ? [] : $this->groups($projects),
        ];
    }

    /**
     * @return array{
     *     ids: list<int>,
     *     columns: list<string>,
     *     rows: list<array<string, string|null>>,
     *     groups: list<array{value: string, count: int}>
     * }
     */
    public function execute(?User $actor, Query $query): array
    {
        if (! $this->saved->canView($actor, $query)) {
            throw new PermissionDeniedException('view_project');
        }

        return $this->run(
            $actor,
            (string) $query->type,
            QueryPayload::filters($query->filters),
            QueryPayload::columnNames($query->column_names),
            QueryPayload::sort($query->sort_criteria) ?? [],
            is_string($query->group_by) ? $query->group_by : null,
        );
    }

    private function assertFilter(QueryFilter $filter, bool $admin): void
    {
        $type = match ($filter->field) {
            'status', 'id', 'is_public' => 'list',
            'parent_id' => 'list_subprojects',
            'name', 'description' => 'text',
            'created_on', 'updated_on' => 'date_past',
            default => throw new QueryValidationException('Filter field is not available: '.$filter->field.'.'),
        };
        if ($filter->field === 'status') {
            foreach ($filter->values as $value) {
                $allowed = $admin ? ['1', '5', '9', '10'] : ['1', '5'];
                if (! in_array($value, $allowed, true)) {
                    throw new QueryValidationException('Project status is not available: '.$value.'.');
                }
            }
        }
        OperatorMatrix::assert($type, $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
    }

    /**
     * @param  list<string>|null  $columns
     * @return list<string>
     */
    private function columns(?array $columns): array
    {
        $requested = $columns ?? self::DEFAULT_COLUMNS;
        $names = [];
        foreach ($requested as $name) {
            if (! in_array($name, self::COLUMNS, true) || in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    private function assertSort(string $column, string $direction): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new QueryValidationException('Sort column is not available: '.$column.'.');
        }
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new QueryValidationException('Sort direction is invalid.');
        }
    }

    private function included(?User $actor, Project $project, bool $admin): bool
    {
        if ($admin) {
            return true;
        }
        $status = (int) $project->status;
        if ($status !== Project::STATUS_ACTIVE && $status !== Project::STATUS_CLOSED) {
            return false;
        }

        return $this->permissions->projectVisible($actor, $project);
    }

    /**
     * @param  list<QueryFilter>  $filters
     */
    private function matches(?User $actor, Project $project, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (! $this->match($actor, $project, $filter)) {
                return false;
            }
        }

        return true;
    }

    private function match(?User $actor, Project $project, QueryFilter $filter): bool
    {
        $operator = $filter->operator;
        if ($filter->field === 'status' || $filter->field === 'id' || $filter->field === 'is_public' || $filter->field === 'parent_id') {
            return $this->matchList($project, $filter);
        }
        if ($filter->field === 'name' || $filter->field === 'description') {
            $column = $filter->field === 'name' ? (string) $project->name : (string) ($project->description ?? '');

            return $this->matchText($column, $operator, $filter->values[0] ?? '');
        }

        $raw = $filter->field === 'created_on' ? $project->created_on : $project->updated_on;
        $stamp = $raw instanceof DateTimeInterface ? $raw->format('Y-m-d H:i:s') : null;

        return $this->matchDate($actor, $stamp, $filter);
    }

    private function matchList(Project $project, QueryFilter $filter): bool
    {
        $value = match ($filter->field) {
            'status' => (string) $project->status,
            'id' => (string) $project->id,
            'is_public' => $project->is_public ? '1' : '0',
            'parent_id' => $project->parent_id === null ? null : (string) $project->parent_id,
            default => null,
        };
        $operator = $filter->operator;
        if ($operator === '*') {
            return $value !== null;
        }
        if ($operator === '!*') {
            return $value === null;
        }
        $hit = $value !== null && in_array($value, $filter->values, true);
        if ($operator === '=') {
            return $hit;
        }

        return ! $hit;
    }

    private function matchText(string $haystack, string $operator, string $needle): bool
    {
        $text = mb_strtolower($haystack);
        $token = mb_strtolower($needle);

        return match ($operator) {
            '*' => trim($haystack) !== '',
            '!*' => trim($haystack) === '',
            '~', '*~' => $token !== '' && str_contains($text, $token),
            '!~' => $token === '' || ! str_contains($text, $token),
            '^' => $token !== '' && str_starts_with($text, $token),
            '$' => $token !== '' && str_ends_with($text, $token),
            '=' => $text === $token,
            default => false,
        };
    }

    private function matchDate(?User $actor, ?string $stamp, QueryFilter $filter): bool
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            return $stamp !== null;
        }
        if ($operator === '!*') {
            return $stamp === null;
        }
        if ($stamp === null) {
            return false;
        }
        $dates = DateWindow::forUser($actor);
        if (in_array($operator, ['=', '>=', '<='], true)) {
            $bound = $dates->explicitDatetimes($filter->values[0], $filter->values[0]);
            if ($operator === '=') {
                return $stamp >= $bound[0] && $stamp <= $bound[1];
            }

            return $operator === '>=' ? $stamp >= $bound[0] : $stamp <= $bound[1];
        }
        if ($operator === '><') {
            $bound = $dates->explicitDatetimes($filter->values[0], $filter->values[1]);

            return $stamp >= $bound[0] && $stamp <= $bound[1];
        }
        $days = in_array($operator, DateWindow::OFFSET_OPERATORS, true) ? FilterValues::dayOffset($filter) : 0;
        $bound = $dates->dateTimeBound($operator, $days);
        if ($bound->empty || ($bound->from === null && $bound->to === null)) {
            return false;
        }
        if ($bound->from !== null && $stamp < $bound->from) {
            return false;
        }

        return $bound->to === null || $stamp <= $bound->to;
    }

    /**
     * @param  list<Project>  $projects
     * @param  list<array{0: string, 1: string}>  $sort
     * @param  array<int, string|null>  $activity
     */
    private function sortProjects(array &$projects, array $sort, ?string $group, array $activity): void
    {
        $keys = $sort;
        if ($group !== null) {
            array_unshift($keys, [$group, 'asc']);
        }
        $keys[] = ['lft', 'asc'];
        usort($projects, function (Project $left, Project $right) use ($keys, $activity): int {
            foreach ($keys as [$column, $direction]) {
                $compared = $this->sortValue($left, $column, $activity) <=> $this->sortValue($right, $column, $activity);
                if ($compared !== 0) {
                    return $direction === 'desc' ? -$compared : $compared;
                }
            }

            return 0;
        });
    }

    /**
     * @param  array<int, string|null>  $activity
     */
    private function sortValue(Project $project, string $column, array $activity): string
    {
        return match ($column) {
            'lft', 'parent_id' => sprintf('%08d', (int) $project->lft),
            'name' => mb_strtolower((string) $project->name),
            'status' => sprintf('%08d', (int) $project->status),
            'short_description' => mb_strtolower((string) ($project->description ?? '')),
            'homepage' => mb_strtolower((string) ($project->homepage ?? '')),
            'identifier' => mb_strtolower((string) $project->identifier),
            'is_public' => $project->is_public ? '1' : '0',
            'created_on' => $project->created_on instanceof DateTimeInterface ? $project->created_on->format('Y-m-d H:i:s') : '',
            'updated_on' => $project->updated_on instanceof DateTimeInterface ? $project->updated_on->format('Y-m-d H:i:s') : '',
            'last_activity_date' => $activity[(int) $project->id] ?? '',
            default => '',
        };
    }

    /**
     * @param  list<Project>  $projects
     * @return list<array{value: string, count: int}>
     */
    private function groups(array $projects): array
    {
        $counts = [];
        foreach ($projects as $project) {
            $value = $project->is_public ? '1' : '0';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        $groups = [];
        foreach ($counts as $value => $count) {
            $groups[] = ['value' => (string) $value, 'count' => $count];
        }

        return $groups;
    }

    private function cell(Project $project, string $column, ?string $activity): ?string
    {
        return match ($column) {
            'name' => (string) $project->name,
            'status' => (string) $project->status,
            'short_description' => $project->description === null || $project->description === '' ? null : (string) $project->description,
            'homepage' => $project->homepage === null || $project->homepage === '' ? null : (string) $project->homepage,
            'identifier' => (string) $project->identifier,
            'parent_id' => $project->parent_id === null ? null : (string) $project->parent_id,
            'is_public' => $project->is_public ? '1' : '0',
            'created_on' => $project->created_on instanceof DateTimeInterface ? $project->created_on->format('Y-m-d H:i:s') : null,
            'updated_on' => $project->updated_on instanceof DateTimeInterface ? $project->updated_on->format('Y-m-d H:i:s') : null,
            'last_activity_date' => $activity,
            default => null,
        };
    }

    private function lastActivity(?User $actor, Project $project): ?string
    {
        $latest = null;
        if ($project->isModuleEnabled('issue_tracking')) {
            $visible = $this->issues->apply(Issue::query(), $actor, $project)->get();
            $ids = [];
            foreach ($visible as $issue) {
                $ids[] = (int) $issue->id;
                $latest = $this->later($latest, $issue->created_on);
            }
            if ($ids !== []) {
                $canPrivate = $this->permissions->allowed($actor, 'view_private_notes', $project);
                $journals = Journal::query()
                    ->where('journalized_type', IssueJournalWriter::JOURNALIZED_ISSUE)
                    ->whereIn('journalized_id', $ids)
                    ->withCount('details')
                    ->get();
                foreach ($journals as $journal) {
                    if ($journal->private_notes && ! $canPrivate) {
                        continue;
                    }
                    $notes = is_string($journal->notes) ? trim($journal->notes) : '';
                    if ($notes === '' && (int) $journal->details_count === 0) {
                        continue;
                    }
                    $latest = $this->later($latest, $journal->created_on);
                }
            }
        }
        if ($project->isModuleEnabled('time_tracking') && $actor instanceof User) {
            $entries = $this->timeEntries->apply(TimeEntry::query()->where('project_id', $project->id), $actor, $project)->get();
            foreach ($entries as $entry) {
                $latest = $this->later($latest, $entry->created_on);
            }
        }
        if (! $latest instanceof DateTimeInterface) {
            return null;
        }

        return $latest->format('Y-m-d');
    }

    private function later(?DateTimeInterface $current, mixed $candidate): ?DateTimeInterface
    {
        if (! $candidate instanceof DateTimeInterface) {
            return $current;
        }
        if ($current === null || $candidate > $current) {
            return $candidate;
        }

        return $current;
    }
}
