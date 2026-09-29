<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * `subproject_id` chooses which projects a project-scoped query may read.
 *
 * With no filter, the query project stays alone. `*` adds every descendant.
 * `!*` is that project only. `=` adds listed descendants. `!` adds every
 * descendant except the listed ones. The query project itself is always kept.
 * Ids that are not descendants are ignored.
 *
 * A global query has no anchor project. `*` adds no constraint, `!*` keeps
 * projects whose `parent_id` is null, and `=` / `!` compare `project_id`.
 */
final class SubprojectScope
{
    public function assert(QueryFilter $filter): void
    {
        OperatorMatrix::assert('list_subprojects', $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
    }

    /**
     * @param  list<QueryFilter>  $filters
     * @return list<int>
     */
    public function ids(Project $project, array $filters): array
    {
        $current = null;
        foreach ($filters as $filter) {
            $this->assert($filter);
            $next = $this->one($project, $filter);
            $current = $current === null ? $next : array_values(array_intersect($current, $next));
        }

        return $current ?? [(int) $project->id];
    }

    /**
     * @param  Builder<Issue>  $query
     */
    public function constrainGlobal(Builder $query, QueryFilter $filter): void
    {
        $this->assert($filter);
        $operator = $filter->operator;
        if ($operator === '*') {
            return;
        }

        if ($operator === '!*') {
            $query->whereIn('issues.project_id', function (QueryBuilder $sub): void {
                $sub->select('id')->from('projects')->whereNull('parent_id');
            });

            return;
        }

        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->whereIn('issues.project_id', $ids);

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        if ($ids === []) {
            return;
        }

        $query->whereNotIn('issues.project_id', $ids);
    }

    /**
     * @return list<int>
     */
    private function one(Project $project, QueryFilter $filter): array
    {
        $root = (int) $project->id;
        $descendants = $this->descendantIds($project);

        return match ($filter->operator) {
            '!*' => [$root],
            '*' => array_values(array_unique([$root, ...$descendants])),
            '=' => array_values(array_unique([
                $root,
                ...array_values(array_intersect($descendants, FilterValues::ids($filter))),
            ])),
            '!' => array_values(array_diff(
                [$root, ...$descendants],
                array_values(array_intersect($descendants, FilterValues::ids($filter))),
            )),
            default => throw new QueryValidationException('Operator '.$filter->operator.' is not valid for '.$filter->field.'.'),
        };
    }

    /**
     * @return list<int>
     */
    private function descendantIds(Project $project): array
    {
        $anchor = $project->fresh() ?? $project;
        if ($anchor->lft === null || $anchor->rgt === null) {
            return [];
        }

        $ids = [];
        $rows = Project::query()
            ->where('lft', '>', $anchor->lft)
            ->where('rgt', '<', $anchor->rgt)
            ->orderBy('id')
            ->pluck('id');
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
