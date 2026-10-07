<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Descendant hour columns on an issue query.
 *
 * `total_estimated_hours` sums `estimated_hours` for the issue and the
 * descendants the actor can see (`root_id` and `lft`/`rgt`). A missing sum
 * is `0`. `total_spent_hours` sums time entries on that same nested set.
 * The subtask does not have to be visible. The time entry does: archived
 * projects contribute nothing, and `time_entries_visibility` is `all`, `own`,
 * or nothing. The spent column exists only when the actor can view time
 * entries on the query project, or on any project when the query is global.
 */
final class IssueTreeHours
{
    public function __construct(
        private readonly VisibleIssueScope $issues,
        private readonly TimeEntryVisibility $timeEntries,
        private readonly PermissionService $permissions,
    ) {}

    public function spentAvailable(?User $actor, ?Project $project): bool
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return $project === null || $this->permissions->allowed($actor, 'view_time_entries', $project);
        }

        if ($project !== null) {
            return $this->permissions->allowed($actor, 'view_time_entries', $project);
        }

        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->permissions->allowed($actor, 'view_time_entries', $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $issueIds
     * @return array<int, string>
     */
    public function estimated(?User $actor, array $issueIds): array
    {
        return $this->map($issueIds, $this->estimatedOrder($actor));
    }

    /**
     * @param  list<int>  $issueIds
     * @return array<int, string>
     */
    public function spent(?User $actor, array $issueIds): array
    {
        return $this->map($issueIds, $this->spentOrder($actor));
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    public function estimatedOrder(?User $actor): array
    {
        $ids = $this->visibleIds($actor);
        if ($ids === []) {
            return ['0', []];
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));

        return [
            'COALESCE((SELECT SUM(tree_est.estimated_hours) FROM issues AS tree_est WHERE tree_est.root_id = issues.root_id AND tree_est.lft >= issues.lft AND tree_est.rgt <= issues.rgt AND tree_est.id IN ('.$marks.')), 0)',
            $ids,
        ];
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    public function spentOrder(?User $actor): array
    {
        [$clause, $bindings] = $this->spentClause($actor);

        return [
            'COALESCE((SELECT SUM(time_entries.hours) FROM time_entries INNER JOIN issues AS tree_spent ON tree_spent.id = time_entries.issue_id WHERE tree_spent.root_id = issues.root_id AND tree_spent.lft >= issues.lft AND tree_spent.rgt <= issues.rgt AND ('.$clause.')), 0)',
            $bindings,
        ];
    }

    /**
     * @param  list<int>  $issueIds
     * @param  array{0: string, 1: list<int>}  $order
     * @return array<int, string>
     */
    private function map(array $issueIds, array $order): array
    {
        [$sql, $bindings] = $order;
        $map = [];
        foreach ($issueIds as $id) {
            $value = DB::table('issues')
                ->where('issues.id', $id)
                ->selectRaw($sql.' as tree_hours', $bindings)
                ->value('tree_hours');
            $map[$id] = PlainDecimal::text($value);
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    private function visibleIds(?User $actor): array
    {
        $ids = [];
        foreach ($this->issues->apply(Issue::query(), $actor, null)->pluck('issues.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function spentClause(?User $actor): array
    {
        if (! $actor instanceof User) {
            return ['1 = 0', []];
        }

        $all = [];
        $own = [];
        foreach (Project::query()->orderBy('id')->get() as $project) {
            $mode = $this->timeEntries->mode($actor, $project);
            if ($mode === TimeEntryVisibility::ALL) {
                $all[] = (int) $project->id;
            } elseif ($mode === TimeEntryVisibility::OWN) {
                $own[] = (int) $project->id;
            }
        }

        $parts = [];
        $bindings = [];
        if ($all !== []) {
            $parts[] = 'time_entries.project_id IN ('.implode(',', array_fill(0, count($all), '?')).')';
            array_push($bindings, ...$all);
        }
        if ($own !== []) {
            $parts[] = '(time_entries.project_id IN ('.implode(',', array_fill(0, count($own), '?')).') AND time_entries.user_id = ?)';
            array_push($bindings, ...$own);
            $bindings[] = (int) $actor->id;
        }
        if ($parts === []) {
            return ['1 = 0', []];
        }

        return ['('.implode(' OR ', $parts).')', $bindings];
    }
}
