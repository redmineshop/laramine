<?php

namespace App\Domain\Issues;

use App\Domain\Acl\PermissionService;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;

/**
 * Visibility and write permissions for one issue journal note.
 *
 * A private note stays hidden without view_private_notes, including from its
 * author. Quote uses add_issue_notes. Edit and delete use edit_issue_notes, or
 * edit_own_issue_notes when journals.user_id is the actor. Active admins pass
 * through PermissionService.
 */
final class JournalNoteAccess
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function canView(User $actor, Project $project, bool $privateNotes): bool
    {
        if (! $privateNotes) {
            return true;
        }

        return $this->permissions->allowed($actor, 'view_private_notes', $project);
    }

    public function canQuote(User $actor, Project $project): bool
    {
        return $this->permissions->allowed($actor, 'add_issue_notes', $project);
    }

    public function canEdit(User $actor, Project $project, Journal $journal): bool
    {
        if ($this->permissions->allowed($actor, 'edit_issue_notes', $project)) {
            return true;
        }

        return (int) $journal->user_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_issue_notes', $project);
    }
}
