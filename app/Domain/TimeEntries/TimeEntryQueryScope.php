<?php

namespace App\Domain\TimeEntries;

use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Visible time-entry rows for a list or a report.
 *
 * Archived projects contribute nothing, including for an active admin.
 * Active and closed projects use `time_entries_visibility`. A project-scoped
 * query keeps that one project and does not add its descendants.
 */
final class TimeEntryQueryScope
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TimeEntryVisibility $visibility,
    ) {}

    /**
     * @param  Builder<TimeEntry>  $query
     * @return Builder<TimeEntry>
     */
    public function apply(Builder $query, User $actor, ?Project $limitedTo): Builder
    {
        $projects = Project::query()
            ->whereIn('status', [Project::STATUS_ACTIVE, Project::STATUS_CLOSED])
            ->get();

        /** @var list<int> $all */
        $all = [];
        /** @var list<int> $own */
        $own = [];
        foreach ($projects as $project) {
            if ($limitedTo instanceof Project && (int) $project->id !== (int) $limitedTo->id) {
                continue;
            }
            if (! $this->permissions->projectVisible($actor, $project)) {
                continue;
            }
            $mode = $this->visibility->mode($actor, $project);
            if ($mode === TimeEntryVisibility::ALL) {
                $all[] = (int) $project->id;
            } elseif ($mode === TimeEntryVisibility::OWN) {
                $own[] = (int) $project->id;
            }
        }

        if ($all === [] && $own === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($all, $own, $actor): void {
            if ($all !== []) {
                $outer->orWhereIn('time_entries.project_id', $all);
            }
            if ($own !== []) {
                $outer->orWhere(function (Builder $inner) use ($own, $actor): void {
                    $inner->whereIn('time_entries.project_id', $own)
                        ->where('time_entries.user_id', (int) $actor->id);
                });
            }
        });
    }
}
