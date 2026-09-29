<?php

namespace App\Policies;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Models\Issue;
use App\Models\User;

/**
 * Issue abilities. Status changes are decided by the workflow service.
 */
class IssuePolicy
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $visibility,
    ) {}

    public function view(?User $user, Issue $issue): bool
    {
        return $this->visibility->canSee($user, $issue);
    }

    public function update(?User $user, Issue $issue): bool
    {
        $project = $issue->project;
        if ($project === null || $user === null) {
            return false;
        }
        if ($user->admin && $user->isActive()) {
            return true;
        }
        if ($this->permissions->allowed($user, 'edit_issues', $project)) {
            return true;
        }

        return (int) $issue->author_id === (int) $user->id
            && $this->permissions->allowed($user, 'edit_own_issues', $project);
    }

    public function delete(?User $user, Issue $issue): bool
    {
        $project = $issue->project;
        if ($project === null) {
            return false;
        }

        return $this->permissions->allowed($user, 'delete_issues', $project);
    }
}
