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
    ) {}

    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function apply(Builder $query, ?User $user, ?Project $project): Builder
    {
        if ($project !== null) {
            return $this->visibility->apply($query, $user, $project);
        }

        if ($user !== null && $user->admin && $user->isActive()) {
            return $query;
        }

        $groups = [
            IssueVisibility::ALL => [],
            IssueVisibility::DEFAULT => [],
            IssueVisibility::OWN => [],
        ];

        foreach (Project::query()->orderBy('id')->get() as $candidate) {
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
