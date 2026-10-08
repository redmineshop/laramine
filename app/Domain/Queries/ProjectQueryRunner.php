<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Domain\Activity\ActivityProvider;
use App\Domain\PermissionDeniedException;
use App\Models\Project;
use App\Models\Query;
use App\Models\User;
use DateTimeInterface;

/**
 * Runs `ProjectQuery` and `ProjectAdminQuery`.
 *
 * A project query sees active and closed projects the actor can see.
 * Archived projects and projects scheduled for deletion stay out, including
 * for an administrator. An admin query is the opposite scope: every status,
 * and only an active administrator can run it. Project custom fields are
 * filters, columns, sort keys, and totals. `is_public` is the groupable
 * column. A blank sort finishes on `lft`. `last_activity_date` is the
 * calendar date of the latest visible activity event. Changesets are not
 * included.
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
        private readonly SavedQueryService $saved,
        private readonly ActivityProvider $activity,
        private readonly ProjectQueryFields $fields,
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
            $this->fields->assertFilter($filter, $actor, $admin);
        }
        $names = $this->fields->columns($actor, $columns, self::COLUMNS, self::DEFAULT_COLUMNS);
        $group = $groupBy === null || $groupBy === '' ? null : $groupBy;
        if ($group !== null && $group !== 'is_public') {
            throw new QueryValidationException('Group column is not available: '.$group.'.');
        }
        foreach ($sort as [$column, $direction]) {
            $this->fields->assertSort($column, $direction, $actor, self::COLUMNS);
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
        $this->sortProjects($actor, $projects, $sort, $group, $activity);

        $rows = [];
        $ids = [];
        foreach ($projects as $project) {
            $ids[] = (int) $project->id;
            $cells = [];
            foreach ($names as $name) {
                $cells[$name] = $this->cell($actor, $project, $name, $activity[(int) $project->id]);
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

    /**
     * Sums project custom-field totals over the same projects `run` would return.
     *
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function totals(?User $actor, string $type, array $filters, array $names): array
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
            $this->fields->assertFilter($filter, $actor, $admin);
        }
        $projects = [];
        foreach (Project::query()->orderBy('lft')->get() as $project) {
            if ($this->included($actor, $project, $admin) && $this->matches($actor, $project, $parsed)) {
                $projects[] = $project;
            }
        }

        return $this->fields->totals($actor, $projects, $names);
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
        if (str_starts_with($filter->field, 'cf_')) {
            return $this->fields->matches($actor, $project, $filter);
        }
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
    private function sortProjects(?User $actor, array &$projects, array $sort, ?string $group, array $activity): void
    {
        $keys = $sort;
        if ($group !== null) {
            array_unshift($keys, [$group, 'asc']);
        }
        $keys[] = ['lft', 'asc'];
        usort($projects, function (Project $left, Project $right) use ($actor, $keys, $activity): int {
            foreach ($keys as [$column, $direction]) {
                $compared = $this->sortValue($actor, $left, $column, $activity) <=> $this->sortValue($actor, $right, $column, $activity);
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
    private function sortValue(?User $actor, Project $project, string $column, array $activity): string
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
            default => $this->fields->sortKey($actor, $project, $column),
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

    private function cell(?User $actor, Project $project, string $column, ?string $activity): ?string
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
            default => $this->fields->cell($actor, $project, $column),
        };
    }

    private function lastActivity(?User $actor, Project $project): ?string
    {
        return $this->activity->latestDate($actor, $project);
    }
}
