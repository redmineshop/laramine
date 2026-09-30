<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Private journal notes follow the same rule as `updated_by` and `last_updated_by`.
 *
 * An active admin sees every note. Otherwise a private note is visible only when
 * the actor has `view_private_notes` on the issue project.
 */
final class JournalVisibility
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function constrain(QueryBuilder $sub, ?User $actor, ?Project $project): void
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        if ($project !== null) {
            if (! $this->permissions->allowed($actor, 'view_private_notes', $project)) {
                $sub->where('journals.private_notes', false);
            }

            return;
        }

        $allowed = $this->projectsAllowing($actor);
        if ($allowed === []) {
            $sub->where('journals.private_notes', false);

            return;
        }

        $sub->where(function (QueryBuilder $visible) use ($allowed): void {
            $visible->where('journals.private_notes', false)
                ->orWhereIn('issues.project_id', $allowed);
        });
    }

    /**
     * SQL fragment for a scalar subquery. Project ids come from this database.
     */
    public function sql(?User $actor, ?Project $project): string
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return '1 = 1';
        }

        if ($project !== null) {
            if ($this->permissions->allowed($actor, 'view_private_notes', $project)) {
                return '1 = 1';
            }

            return 'journals.private_notes = 0';
        }

        $allowed = $this->projectsAllowing($actor);
        if ($allowed === []) {
            return 'journals.private_notes = 0';
        }

        return '(journals.private_notes = 0 OR issues.project_id IN ('.implode(',', $allowed).'))';
    }

    /**
     * @return list<int>
     */
    private function projectsAllowing(?User $actor): array
    {
        $allowed = [];
        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->permissions->allowed($actor, 'view_private_notes', $candidate)) {
                $allowed[] = (int) $candidate->id;
            }
        }

        return $allowed;
    }
}
