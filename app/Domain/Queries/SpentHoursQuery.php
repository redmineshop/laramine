<?php

namespace App\Domain\Queries;

use App\Domain\Acl\TimeEntryVisibility;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Per-issue spent hours for query totals and the `spent_hours` column.
 *
 * Each issue contributes `COALESCE(ROUND(SUM(hours), 2), 0)` for time entries
 * on that issue's project. `time_entries_visibility` then keeps `all`, `own`
 * (`user_id` is the actor), or nothing. Active admins see every entry.
 * Several roles use the most open value, and only roles that grant
 * `view_time_entries` count. The `spent_time` filter does not use this scope.
 */
final class SpentHoursQuery
{
    public function __construct(private readonly TimeEntryVisibility $visibility) {}

    /**
     * @param  Builder<Issue>  $issues
     */
    public function total(Builder $issues, ?User $actor): string
    {
        $total = DB::query()
            ->fromSub($this->perIssueSelect($issues, $actor), 'spent_totals')
            ->selectRaw('COALESCE(SUM(per_issue), 0) as total')
            ->value('total');

        return PlainDecimal::text($total);
    }

    /**
     * @param  Builder<Issue>  $issues
     * @return array<int, string>
     */
    public function perIssue(Builder $issues, ?User $actor): array
    {
        $map = [];
        foreach ($this->perIssueSelect($issues, $actor)->pluck('per_issue', 'issue_id') as $id => $hours) {
            if (! is_numeric($id)) {
                continue;
            }
            $map[(int) $id] = PlainDecimal::text($hours);
        }

        return $map;
    }

    /**
     * @param  Builder<Issue>  $issues
     */
    private function perIssueSelect(Builder $issues, ?User $actor): QueryBuilder
    {
        return DB::table('issues')
            ->whereIn('issues.id', $this->scopedIds($issues))
            ->leftJoin('time_entries', function (JoinClause $join) use ($issues, $actor): void {
                $join->on('time_entries.issue_id', '=', 'issues.id')
                    ->on('time_entries.project_id', '=', 'issues.project_id');
                $this->constrain($join, $actor, $issues);
            })
            ->groupBy('issues.id')
            ->selectRaw('issues.id as issue_id, COALESCE(ROUND(CAST(SUM(time_entries.hours) AS DECIMAL(30,3)), 2), 0) as per_issue');
    }

    /**
     * @param  Builder<Issue>  $issues
     */
    private function constrain(JoinClause $join, ?User $actor, Builder $issues): void
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        if (! $actor instanceof User) {
            $join->whereRaw('1 = 0');

            return;
        }

        $all = [];
        $own = [];
        $projectIds = $this->projectIds($issues);
        if ($projectIds !== []) {
            foreach (Project::query()->whereIn('id', $projectIds)->get() as $project) {
                $mode = $this->visibility->mode($actor, $project);
                $projectId = (int) $project->id;
                if ($mode === TimeEntryVisibility::ALL) {
                    $all[] = $projectId;
                } elseif ($mode === TimeEntryVisibility::OWN) {
                    $own[] = $projectId;
                }
            }
        }

        if ($all === [] && $own === []) {
            $join->whereRaw('1 = 0');

            return;
        }

        $userId = (int) $actor->id;
        $join->where(function (QueryBuilder $inner) use ($all, $own, $userId): void {
            if ($all !== []) {
                $inner->whereIn('issues.project_id', $all);
            }
            if ($own === []) {
                return;
            }

            $ownScope = function (QueryBuilder $query) use ($own, $userId): void {
                $query->whereIn('issues.project_id', $own)
                    ->where('time_entries.user_id', $userId);
            };
            if ($all === []) {
                $ownScope($inner);

                return;
            }

            $inner->orWhere($ownScope);
        });
    }

    /**
     * @param  Builder<Issue>  $issues
     * @return Builder<Issue>
     */
    private function scopedIds(Builder $issues): Builder
    {
        $scoped = clone $issues;

        return $scoped->reorder()->select('issues.id')->distinct();
    }

    /**
     * @param  Builder<Issue>  $issues
     * @return list<int>
     */
    private function projectIds(Builder $issues): array
    {
        $ids = [];
        $scoped = clone $issues;
        foreach ($scoped->reorder()->select('issues.project_id')->distinct()->pluck('issues.project_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
