<?php

namespace App\Domain\Queries;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Limits an issue query to rows the actor may view.
 *
 * A project scope reuses `IssueVisibility`. A global query ORs the same
 * `all` / `default` / `own` rules across every project where `view_issues` is allowed.
 */
final class VisibleIssueScope
{
    public function __construct(
        private readonly IssueVisibility $visibility,
        private readonly PermissionService $permissions,
        private readonly MembershipService $memberships,
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

        if ($user !== null && $user->admin && $user->isActive()) {
            if ($onlyIds === null) {
                return $query;
            }

            return $query->whereIn('issues.project_id', $onlyIds);
        }

        $groups = [
            IssueVisibility::ALL => [],
            IssueVisibility::DEFAULT => [],
            IssueVisibility::OWN => [],
        ];

        $projects = Project::query()->orderBy('id');
        if ($onlyIds !== null) {
            $projects->whereIn('id', $onlyIds);
        }

        foreach ($projects->get() as $candidate) {
            if (! $this->permissions->allowed($user, 'view_issues', $candidate)) {
                continue;
            }
            $mode = $this->visibility->effective($this->permissions->rolesFor($user, $candidate));
            $projectId = (int) $candidate->id;
            if ($mode === IssueVisibility::ALL) {
                $groups[IssueVisibility::ALL][] = $projectId;
            } elseif ($mode === IssueVisibility::DEFAULT) {
                $groups[IssueVisibility::DEFAULT][] = $projectId;
            } elseif ($mode === IssueVisibility::OWN) {
                $groups[IssueVisibility::OWN][] = $projectId;
            }
        }

        $principalIds = $user instanceof User && $this->permissions->isLoggedIn($user)
            ? $this->memberships->principalIds($user)
            : [];

        if ($groups[IssueVisibility::ALL] === []
            && $groups[IssueVisibility::DEFAULT] === []
            && $groups[IssueVisibility::OWN] === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($groups, $principalIds): void {
            /** @var Builder<Issue> $outer */
            $this->whereProjects($outer, $groups[IssueVisibility::ALL], null, $principalIds);
            $this->whereProjects($outer, $groups[IssueVisibility::DEFAULT], IssueVisibility::DEFAULT, $principalIds);
            $this->whereProjects($outer, $groups[IssueVisibility::OWN], IssueVisibility::OWN, $principalIds);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<int>  $projectIds
     * @param  list<int>  $principalIds
     */
    private function whereProjects(Builder $query, array $projectIds, ?string $mode, array $principalIds): void
    {
        if ($projectIds === []) {
            return;
        }

        $query->orWhere(function (Builder $inner) use ($projectIds, $mode, $principalIds): void {
            /** @var Builder<Issue> $inner */
            $inner->whereIn('issues.project_id', $projectIds);
            if ($mode === IssueVisibility::DEFAULT) {
                $inner->where(function (Builder $visible) use ($principalIds): void {
                    /** @var Builder<Issue> $visible */
                    $visible->where('issues.is_private', false);
                    if ($principalIds !== []) {
                        $visible->orWhereIn('issues.author_id', $principalIds)
                            ->orWhereIn('issues.assigned_to_id', $principalIds);
                    }
                });
            }
            if ($mode === IssueVisibility::OWN) {
                if ($principalIds === []) {
                    $inner->whereRaw('1 = 0');

                    return;
                }
                $inner->where(function (Builder $owned) use ($principalIds): void {
                    /** @var Builder<Issue> $owned */
                    $owned->whereIn('issues.author_id', $principalIds)
                        ->orWhereIn('issues.assigned_to_id', $principalIds);
                });
            }
        });
    }
}
