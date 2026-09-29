<?php

namespace App\Domain\Acl;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Permission checks by Redmine permission name.
 *
 * Admin users bypass project checks. Public permissions are implied for every
 * applicable role. Modular permissions also require the module on the project.
 */
final class PermissionService
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly MembershipService $memberships,
    ) {}

    public function allowed(?User $user, string $permission, ?Project $project = null): bool
    {
        $definition = $this->catalog->definition($permission);

        if ($this->isActiveAdmin($user)) {
            return true;
        }

        if ($definition->isModular()) {
            if ($project === null || ! $project->isModuleEnabled($definition->module)) {
                return false;
            }
        }

        if (! $this->passesRequirement($definition, $user, $project)) {
            return false;
        }

        if ($definition->public) {
            return $project === null || $this->projectVisible($user, $project);
        }

        foreach ($this->rolesFor($user, $project) as $role) {
            if ($role->grants($definition->name)) {
                return true;
            }
        }

        return false;
    }

    public function projectVisible(?User $user, Project $project): bool
    {
        if ($this->isActiveAdmin($user)) {
            return true;
        }

        if ($user !== null && $this->isLoggedIn($user) && $this->memberships->isMember($user, $project)) {
            return true;
        }

        return (bool) $project->is_public;
    }

    /**
     * @return Collection<int, Role>
     */
    public function rolesFor(?User $user, ?Project $project): Collection
    {
        if ($project === null) {
            if (! $user instanceof User || ! $this->isLoggedIn($user)) {
                return $this->builtinRoles(BuiltinRole::ANONYMOUS);
            }

            $roles = $this->memberships->rolesAcrossProjects($user);
            if ($roles->isEmpty()) {
                return $this->builtinRoles(BuiltinRole::NON_MEMBER);
            }

            return $roles;
        }

        if (! $this->projectVisible($user, $project)) {
            return new Collection;
        }

        if ($user instanceof User && $this->isLoggedIn($user)) {
            if ($this->memberships->isMember($user, $project)) {
                return $this->memberships->rolesOnProject($user, $project);
            }

            return $this->builtinRoles(BuiltinRole::NON_MEMBER);
        }

        return $this->builtinRoles(BuiltinRole::ANONYMOUS);
    }

    public function isLoggedIn(?User $user): bool
    {
        return $user !== null
            && $user->type !== User::TYPE_ANONYMOUS
            && $user->isActive();
    }

    private function passesRequirement(PermissionDefinition $definition, ?User $user, ?Project $project): bool
    {
        $require = $definition->require;

        return match ($require) {
            null => true,
            'loggedin' => $this->isLoggedIn($user),
            'member' => $project !== null && $user !== null && $this->isLoggedIn($user) && $this->memberships->isMember($user, $project),
        };
    }

    private function isActiveAdmin(?User $user): bool
    {
        return $user !== null && $user->admin && $user->isActive();
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
