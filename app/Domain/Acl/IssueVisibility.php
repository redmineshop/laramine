<?php

namespace App\Domain\Acl;

use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Issue list scope for `roles.issues_visibility`.
 *
 * `all` includes private issues. `default` hides private issues unless the
 * user is the author or the assignee (or a member of the assignee group).
 * `own` keeps only those author/assignee rows. Roles that grant
 * `view_issues` are combined with OR, including each role's tracker mask.
 * Active admins are not filtered by role when the project still allows
 * `view_issues`.
 */
final class IssueVisibility
{
    public const ALL = 'all';

    public const DEFAULT = 'default';

    public const OWN = 'own';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly MembershipService $memberships,
        private readonly TrackerPermissionMask $trackers,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function apply(Builder $query, ?User $user, Project $project): Builder
    {
        if (! $this->permissions->allowed($user, 'view_issues', $project)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('issues.project_id', $project->id);

        if ($user !== null && $user->admin && $user->isActive()) {
            return $query;
        }

        /** @var list<Role> $roles */
        $roles = [];
        foreach ($this->permissions->rolesFor($user, $project) as $role) {
            if ($role->grants('view_issues')) {
                $roles[] = $role;
            }
        }
        if ($roles === []) {
            return $query->whereRaw('1 = 0');
        }

        /** @var list<array{0: Role, 1: list<int>|null}> $clauses */
        $clauses = [];
        foreach ($roles as $role) {
            $trackerIds = $this->trackers->ids($role, 'view_issues');
            if ($trackerIds === []) {
                continue;
            }
            $clauses[] = [$role, $trackerIds];
        }
        if ($clauses === []) {
            return $query->whereRaw('1 = 0');
        }

        $principalIds = $user instanceof User && $this->permissions->isLoggedIn($user)
            ? $this->memberships->principalIds($user)
            : [];

        return $query->where(function (Builder $outer) use ($clauses, $principalIds): void {
            $this->orRoleClauses($outer, $clauses, $principalIds);
        });
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<array{0: Role, 1: list<int>|null}>  $clauses
     * @param  list<int>  $principalIds
     */
    private function orRoleClauses(Builder $query, array $clauses, array $principalIds): void
    {
        foreach ($clauses as [$role, $trackerIds]) {
            $query->orWhere(function (Builder $inner) use ($role, $trackerIds, $principalIds): void {
                /** @var Builder<Issue> $inner */
                $this->visibilityPredicate($inner, (string) $role->issues_visibility, $principalIds);
                if (is_array($trackerIds)) {
                    $inner->whereIn('issues.tracker_id', $trackerIds);
                }
            });
        }
    }

    public function canSee(?User $user, Issue $issue): bool
    {
        $project = $issue->project;
        if ($project === null) {
            return false;
        }

        return $this->apply(Issue::query(), $user, $project)->whereKey($issue->id)->exists();
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    public function effective(Collection $roles): string
    {
        $rank = [
            self::OWN => 1,
            self::DEFAULT => 2,
            self::ALL => 3,
        ];
        $best = self::DEFAULT;
        $bestRank = 0;
        foreach ($roles as $role) {
            $value = (string) $role->issues_visibility;
            $current = $rank[$value] ?? 0;
            if ($current > $bestRank) {
                $best = $value;
                $bestRank = $current;
            }
        }

        return $best;
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<int>  $principalIds
     */
    private function visibilityPredicate(Builder $query, string $mode, array $principalIds): void
    {
        if ($mode === self::ALL) {
            $query->whereRaw('1 = 1');

            return;
        }

        if ($mode === self::OWN) {
            $this->whereAuthorOrAssignee($query, $principalIds);

            return;
        }

        $query->where(function (Builder $inner) use ($principalIds): void {
            /** @var Builder<Issue> $inner */
            $inner->where('issues.is_private', false);
            if ($principalIds !== []) {
                $inner->orWhereIn('issues.author_id', $principalIds)
                    ->orWhereIn('issues.assigned_to_id', $principalIds);
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     * @param  list<int>  $principalIds
     */
    private function whereAuthorOrAssignee(Builder $query, array $principalIds): void
    {
        if ($principalIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $inner) use ($principalIds): void {
            /** @var Builder<Issue> $inner */
            $inner->whereIn('issues.author_id', $principalIds)
                ->orWhereIn('issues.assigned_to_id', $principalIds);
        });
    }
}
