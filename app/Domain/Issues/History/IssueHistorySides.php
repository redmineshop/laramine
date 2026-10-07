<?php

namespace App\Domain\Issues\History;

use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Spent time and associated revisions beside the journal tabs.
 *
 * Spent time needs `view_time_entries` and an hours sum above zero. The rows
 * themselves follow `time_entries_visibility`. Associated revisions are
 * changesets linked in `changesets_issues` whose repository project allows
 * `view_changesets`.
 */
final class IssueHistorySides
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TimeEntryVisibility $timeVisibility,
    ) {}

    public function forIssue(User $actor, Issue $issue, Project $project): IssueHistorySideView
    {
        return new IssueHistorySideView(
            $this->spentTimeVisible($actor, $issue, $project),
            $this->timeEntries($actor, $issue, $project),
            $this->changesets($actor, $issue),
        );
    }

    private function spentTimeVisible(User $actor, Issue $issue, Project $project): bool
    {
        if (! $this->permissions->allowed($actor, 'view_time_entries', $project)) {
            return false;
        }

        $sum = TimeEntry::query()->where('issue_id', $issue->id)->sum('hours');

        return (float) $sum > 0;
    }

    /**
     * @return list<HistoryTimeEntryView>
     */
    private function timeEntries(User $actor, Issue $issue, Project $project): array
    {
        if (! $this->spentTimeVisible($actor, $issue, $project)) {
            return [];
        }

        $query = TimeEntry::query()
            ->where('issue_id', $issue->id)
            ->where('project_id', $project->id);
        $rows = $this->timeVisibility
            ->apply($query, $actor, $project)
            ->with(['user', 'activity'])
            ->orderByDesc('spent_on')
            ->orderByDesc('created_on')
            ->orderByDesc('id')
            ->get();

        $views = [];
        foreach ($rows as $row) {
            $views[] = $this->timeEntry($row);
        }

        return $views;
    }

    private function timeEntry(TimeEntry $entry): HistoryTimeEntryView
    {
        $comments = $entry->comments;
        $user = $entry->user;
        $activity = $entry->activity;

        return new HistoryTimeEntryView(
            (int) $entry->id,
            $this->dateText($entry->getRawOriginal('spent_on'), 10),
            round((float) $entry->hours, 2),
            is_string($comments) && $comments !== '' ? $comments : null,
            $user instanceof User ? $this->personName($user) : (string) $entry->user_id,
            $activity instanceof Enumeration && $activity->name !== ''
                ? $activity->name
                : (string) $entry->activity_id,
        );
    }

    /**
     * @return list<HistoryChangesetView>
     */
    private function changesets(User $actor, Issue $issue): array
    {
        $linked = DB::table('changesets_issues')->where('issue_id', $issue->id)->pluck('changeset_id');
        $ids = [];
        foreach ($linked as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = Changeset::query()
            ->whereIn('id', $ids)
            ->with(['repository.project', 'user'])
            ->orderByDesc('committed_on')
            ->orderByDesc('id')
            ->get();

        $views = [];
        foreach ($rows as $row) {
            $view = $this->changeset($actor, $row);
            if ($view !== null) {
                $views[] = $view;
            }
        }

        return $views;
    }

    private function changeset(User $actor, Changeset $changeset): ?HistoryChangesetView
    {
        $repository = $changeset->repository;
        if (! $repository instanceof Repository) {
            return null;
        }
        $project = $repository->project;
        if (! $project instanceof Project) {
            return null;
        }
        if (! $this->permissions->allowed($actor, 'view_changesets', $project)) {
            return null;
        }

        $comments = $changeset->comments;
        $committer = $changeset->committer;
        $user = $changeset->user;
        $identifier = $repository->identifier;
        $committerText = is_string($committer) && $committer !== '' ? $committer : null;

        return new HistoryChangesetView(
            (int) $changeset->id,
            (string) $changeset->revision,
            is_string($comments) && $comments !== '' ? $comments : null,
            $this->dateText($changeset->getRawOriginal('committed_on'), 19),
            $committerText,
            $user instanceof User ? $this->personName($user) : $committerText,
            is_string($identifier) && $identifier !== '' ? $identifier : null,
        );
    }

    private function dateText(mixed $stored, int $length): string
    {
        if (! is_string($stored) || $stored === '') {
            return '';
        }

        return substr($stored, 0, $length);
    }

    private function personName(User $user): string
    {
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);

        return $name !== '' ? $name : (string) $user->login;
    }
}
