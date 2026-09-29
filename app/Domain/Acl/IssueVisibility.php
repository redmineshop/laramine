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
 * `own` keeps only those author/assignee rows. Several roles use the most
 * open value. Active admins are not filtered.
 */
final class IssueVisibility
{
    public const ALL = 'all';

    public const DEFAULT = 'default';

    public const OWN = 'own';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly MembershipService $memberships,
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

        $query->where('project_id', $project->id);

        if ($user !== null && $user->admin && $user->isActive()) {
            return $query;
        }

        $visibility = $this->effective($this->permissions->rolesFor($user, $project));
        $principalIds = $user instanceof User && $this->permissions->isLoggedIn($user)
            ? $this->memberships->principalIds($user)
            : [];

        if ($visibility === self::ALL) {
            return $query;
        }

        if ($visibility === self::OWN) {
            return $this->whereAuthorOrAssignee($query, $principalIds);
        }

        return $query->where(function (Builder $inner) use ($principalIds): void {
            /** @var Builder<Issue> $inner */
            $inner->where('is_private', false);
            if ($principalIds !== []) {
                $inner->orWhereIn('author_id', $principalIds)
                    ->orWhereIn('assigned_to_id', $principalIds);
            }
        });
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
     * @return Builder<Issue>
     */
    private function whereAuthorOrAssignee(Builder $query, array $principalIds): Builder
    {
        if ($principalIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($principalIds): void {
            /** @var Builder<Issue> $inner */
            $inner->whereIn('author_id', $principalIds)
                ->orWhereIn('assigned_to_id', $principalIds);
        });
    }
}
