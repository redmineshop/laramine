<?php

namespace App\Domain\Acl;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Who can see which users, from `roles.users_visibility`.
 *
 * `all` shows every active user and group. `members_of_visible_projects`
 * shows active principals who belong to a project the viewer can see, plus
 * the viewer. Several roles use the most open value. An active admin sees
 * every user and group, including locked accounts. AnonymousUser rows are
 * omitted. The user directory and UserQuery stay deferred.
 */
final class UserVisibility
{
    public const ALL = 'all';

    public const MEMBERS_OF_VISIBLE_PROJECTS = 'members_of_visible_projects';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly MembershipService $memberships,
    ) {}

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function apply(Builder $query, ?User $viewer): Builder
    {
        $query->whereIn('type', [User::TYPE_USER, User::TYPE_GROUP]);

        if ($viewer !== null && $viewer->admin && $viewer->isActive()) {
            return $query;
        }

        if ($this->seesAll($viewer)) {
            return $query->where('status', User::STATUS_ACTIVE);
        }

        $projectIds = $this->visibleProjectIds($viewer);

        return $query->where(function (Builder $inner) use ($viewer, $projectIds): void {
            /** @var Builder<User> $inner */
            $inner->where('status', User::STATUS_ACTIVE)
                ->whereIn('id', function (QueryBuilder $sub) use ($projectIds): void {
                    $sub->select('user_id')->from('members')->whereIn('project_id', $projectIds);
                });
            if ($viewer instanceof User && $this->permissions->isLoggedIn($viewer)) {
                $inner->orWhere('id', $viewer->id);
            }
        });
    }

    public function canSee(?User $viewer, User $subject): bool
    {
        return $this->apply(User::query(), $viewer)->whereKey($subject->id)->exists();
    }

    private function seesAll(?User $viewer): bool
    {
        foreach ($this->visibilityRoles($viewer) as $role) {
            if ((string) $role->users_visibility === self::ALL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Role>
     */
    private function visibilityRoles(?User $viewer): Collection
    {
        if ($viewer instanceof User && $this->permissions->isLoggedIn($viewer)) {
            $roles = $this->memberships->rolesAcrossProjects($viewer);
            if ($roles->isNotEmpty()) {
                return $roles;
            }

            return $this->builtinRoles(BuiltinRole::NON_MEMBER);
        }

        return $this->builtinRoles(BuiltinRole::ANONYMOUS);
    }

    /**
     * @return list<int>
     */
    private function visibleProjectIds(?User $viewer): array
    {
        $ids = [];
        foreach (Project::query()->orderBy('id')->get() as $project) {
            if ($this->permissions->projectVisible($viewer, $project)) {
                $ids[] = (int) $project->id;
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, Role>
     */
    private function builtinRoles(int $builtin): Collection
    {
        $role = Role::query()->where('builtin', $builtin)->first();
        if ($role === null) {
            return new Collection;
        }

        return new Collection([$role]);
    }
}
