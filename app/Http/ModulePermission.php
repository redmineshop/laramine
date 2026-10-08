<?php

namespace App\Http;

use App\Domain\Acl\ModuleGate;
use App\Domain\PermissionDeniedException;
use App\Models\Project;
use App\Models\User;

/**
 * Module and permission check that does not throw.
 */
final class ModulePermission
{
    public function __construct(private readonly ModuleGate $gate) {}

    public function allows(?User $actor, Project $project, string $module, string $permission): bool
    {
        try {
            $this->gate->allow($actor, $project, $module, $permission);

            return true;
        } catch (PermissionDeniedException) {
            return false;
        }
    }
}
