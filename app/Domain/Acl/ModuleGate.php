<?php

namespace App\Domain\Acl;

use App\Domain\PermissionDeniedException;
use App\Models\Project;
use App\Models\User;

/**
 * Module check that applies to an active administrator.
 *
 * `PermissionService` still lets that administrator skip a disabled module.
 * Wiki, forum, and their attachment reads check the module first.
 */
final class ModuleGate
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    public function allow(?User $actor, Project $project, string $module, string $permission): void
    {
        if (! $project->isModuleEnabled($module)) {
            throw new PermissionDeniedException($permission);
        }
        if (! $this->permissions->allowed($actor, $permission, $project)) {
            throw new PermissionDeniedException($permission);
        }
    }
}
