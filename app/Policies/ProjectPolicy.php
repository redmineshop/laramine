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

    public function viewNews(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'view_news', $project);
    }

    public function manageNews(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'manage_news', $project);
    }

    public function viewDocuments(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'view_documents', $project);
    }

    public function addDocuments(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'add_documents', $project);
    }

    public function viewFiles(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'view_files', $project);
    }

    public function manageFiles(?User $user, Project $project): bool
    {
        return $this->permissions->allowed($user, 'manage_files', $project);
    }
}
