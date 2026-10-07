<?php

namespace App\Domain\Queries;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits an issue query to rows the actor may view.
 *
 * A project scope reuses `IssueVisibility`. A wider query ORs that same
 * per-project scope, including tracker masks and closed or archived gates.
 */
final class VisibleIssueScope
{
    public function __construct(
        private readonly IssueVisibility $visibility,
        private readonly PermissionService $permissions,
        private readonly SubprojectScope $subprojects,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     * @param  list<QueryFilter>  $filters
     * @return Builder<Issue>
     */
    public function apply(Builder $query, ?User $user, ?Project $project, array $filters = []): Builder
    {
        $subprojects = [];
        foreach ($filters as $filter) {
            if ($filter->field !== 'subproject_id') {
                continue;
            }
            $this->subprojects->assert($filter);
            $subprojects[] = $filter;
        }

        if ($project !== null && $subprojects === []) {
            $ids = $this->subprojects->ids($project, []);
            if ($ids === [(int) $project->id]) {
                return $this->visibility->apply($query, $user, $project);
            }

            return $this->among($query, $user, $ids);
        }

        if ($project !== null) {
            return $this->among($query, $user, $this->subprojects->ids($project, $subprojects));
        }

        $builder = $this->among($query, $user, null);
        foreach ($subprojects as $filter) {
            $this->subprojects->constrainGlobal($builder, $filter);
        }

        return $builder;
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<int>|null  $onlyIds  Null walks every project. An empty list matches nothing.
     * @return Builder<Issue>
     */
    private function among(Builder $query, ?User $user, ?array $onlyIds): Builder
    {
        if ($onlyIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $projects = Project::query()->orderBy('id');
        if ($onlyIds !== null) {
            $projects->whereIn('id', $onlyIds);
        }
        $candidates = $projects->get();

        if ($user !== null && $user->admin && $user->isActive()) {
            $ids = [];
            foreach ($candidates as $candidate) {
                if ($this->permissions->allowed($user, 'view_issues', $candidate)) {
                    $ids[] = (int) $candidate->id;
                }
            }
            if ($ids === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('issues.project_id', $ids);
        }

        $visible = [];
        foreach ($candidates as $candidate) {
            if ($this->permissions->allowed($user, 'view_issues', $candidate)) {
                $visible[] = $candidate;
            }
        }
        if ($visible === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($user, $visible): void {
            /** @var Builder<Issue> $outer */
            foreach ($visible as $candidate) {
                $outer->orWhere(function (Builder $inner) use ($user, $candidate): void {
                    /** @var Builder<Issue> $inner */
                    $this->visibility->apply($inner, $user, $candidate);
                });
            }
        });
    }
}
