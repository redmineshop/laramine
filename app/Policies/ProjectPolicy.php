<?php

namespace App\Policies;

use App\Domain\Acl\PermissionService;
use App\Models\Project;
use App\Models\User;

/**
 * Project abilities keyed to Redmine permission names.
 */
class ProjectPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function view(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'view_project', $project);
    }

    public function update(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'edit_project', $project);
    }

    public function delete(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'delete_project', $project);
    }
}
