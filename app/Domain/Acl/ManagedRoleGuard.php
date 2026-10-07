<?php

namespace App\Domain\Acl;

use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Assigns a membership role when the actor manages that role.
 *
 * `MembershipService::assignRole` stays the unchecked write used by
 * inheritance and group expansion. This guard is the membership check:
 * `manage_members`, then `all_roles_managed` or `roles_managed_roles`.
 */
final class ManagedRoleGuard
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly MembershipService $memberships,
    ) {}

    public function assign(User $actor, Project $project, User $principal, Role $role): MemberRole
    {
        $this->assertCanAssign($actor, $project, $role);

        return $this->memberships->assignRole($project, $principal, $role);
    }

    public function assertCanAssign(User $actor, Project $project, Role $role): void
    {
        if ((int) $role->builtin !== BuiltinRole::CUSTOM) {
            throw new DomainException('Builtin roles cannot be assigned through membership.');
        }

        if (! $this->permissions->allowed($actor, 'manage_members', $project)) {
            throw new PermissionDeniedException('manage_members');
        }

        if ($actor->admin && $actor->isActive()) {
            return;
        }

        foreach ($this->memberships->rolesOnProject($actor, $project) as $held) {
            if (! $held->grants('manage_members')) {
                continue;
            }
            if ($held->all_roles_managed) {
                return;
            }
            if ($held->managedRoles()->where('roles.id', $role->id)->exists()) {
                return;
            }
        }

        throw new DomainException('Role is not managed by the actor on this project.');
    }
}
