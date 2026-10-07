<?php

namespace App\Policies;

use App\Domain\Acl\PermissionService;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;

/**
 * Document abilities. The project must still grant the matching permission.
 */
class DocumentPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function view(?User $user, Document $document): bool
    {
        return $this->allows($user, $document, 'view_documents');
    }

    public function update(?User $user, Document $document): bool
    {
        return $this->allows($user, $document, 'edit_documents');
    }

    public function delete(?User $user, Document $document): bool
    {
        return $this->allows($user, $document, 'delete_documents');
    }

    private function allows(?User $user, Document $document, string $permission): bool
    {
        $project = $document->project;

        return $project instanceof Project && $this->permissions->allowed($user, $permission, $project);
    }
}
