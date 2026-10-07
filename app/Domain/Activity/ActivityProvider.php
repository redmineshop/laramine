<?php

namespace App\Domain\Activity;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Settings\SettingValue;
use App\Domain\TimeEntries\HourValue;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\EnabledModule;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\News;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Activity for issues, journals, time entries, news, documents, and files.
 *
 * The window ends on `from` (today when omitted) and starts that many days
 * earlier. `activity_days_default` is 30 when the setting is missing.
 * News, documents, and project or version files are included when that module
 * is enabled, including for an administrator. Wiki, messages, and changesets
 * are not providers.
 */
final class ActivityProvider
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $issues,
        private readonly TimeEntryVisibility $timeEntries,
    ) {}

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(?string $from, ?int $days): array
    {
        $end = $this->day($from) ?? Carbon::now()->startOfDay();
        $span = $days ?? $this->settings->activityDaysDefault();
        if ($span < 0) {
            $span = $this->settings->activityDaysDefault();
        }

        return [$end->copy()->subDays($span), $end->copy()->addDay()];
    }

    /**
     * @return list<ActivityEvent>
     */
    public function events(User $actor, ?Project $project, ?string $from, ?int $days): array
    {
        [$start, $until] = $this->window($from, $days);
        $events = [];
        foreach ($this->projects($actor, $project) as $candidate) {
            if ($this->moduleEnabled($candidate, 'issue_tracking')) {
                array_push($events, ...$this->issueEvents($actor, $candidate, $start, $until));
            }
            if ($this->moduleEnabled($candidate, 'time_tracking')) {
                array_push($events, ...$this->timeEvents($actor, $candidate, $start, $until));
            }
            if ($this->moduleEnabled($candidate, 'news')) {
                array_push($events, ...$this->newsEvents($actor, $candidate, $start, $until));
            }
            if ($this->moduleEnabled($candidate, 'documents')) {
                array_push($events, ...$this->documentEvents($actor, $candidate, $start, $until));
            }
            if ($this->moduleEnabled($candidate, 'files')) {
                array_push($events, ...$this->fileEvents($actor, $candidate, $start, $until));
            }
        }

        usort($events, function (ActivityEvent $left, ActivityEvent $right): int {
            $byTime = strcmp($right->at, $left->at);
            if ($byTime !== 0) {
                return $byTime;
            }
            $byKind = strcmp($left->kind, $right->kind);
            if ($byKind !== 0) {
                return $byKind;
            }

            return $right->id <=> $left->id;
        });

        return $events;
    }

    /**
     * Visible issues for an Atom issue list, newest id first.
     *
     * @return list<ActivityEvent>
     */
    public function issueList(User $actor, ?Project $project): array
    {
        $events = [];
        foreach ($this->projects($actor, $project) as $candidate) {
            if (! $this->moduleEnabled($candidate, 'issue_tracking')) {
                continue;
            }
            if (! $this->permissions->allowed($actor, 'view_issues', $candidate)) {
                continue;
            }
            $issues = $this->issues->apply(Issue::query(), $actor, $candidate)
                ->with(['tracker', 'status', 'author'])
                ->orderByDesc('id')
                ->get();
            foreach ($issues as $issue) {
                $event = $this->issueEvent($issue, $candidate);
                if ($event instanceof ActivityEvent) {
                    $events[] = $event;
                }
            }
        }

        usort($events, fn (ActivityEvent $left, ActivityEvent $right): int => $right->id <=> $left->id);

        return $events;
    }

    /**
     * @return list<Project>
     */
    private function projects(User $actor, ?Project $project): array
    {
        if ($project instanceof Project) {
            return $this->projectVisible($actor, $project) ? [$project] : [];
        }

        $projects = [];
        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->projectVisible($actor, $candidate)) {
                $projects[] = $candidate;
            }
        }

        return $projects;
    }

    private function projectVisible(User $actor, Project $project): bool
    {
        $status = (int) $project->status;
        if ($status !== Project::STATUS_ACTIVE && $status !== Project::STATUS_CLOSED) {
            return false;
        }

        return $this->permissions->allowed($actor, 'view_project', $project);
    }

    private function moduleEnabled(Project $project, string $name): bool
    {
        return EnabledModule::query()
            ->where('project_id', $project->id)
            ->where('name', $name)
            ->exists();
    }

    /**
     * @return list<ActivityEvent>
     */
    private function issueEvents(User $actor, Project $project, Carbon $start, Carbon $until): array
    {
        if (! $this->permissions->allowed($actor, 'view_issues', $project)) {
            return [];
        }

        $visible = $this->issues->apply(Issue::query(), $actor, $project)->pluck('id')->all();
        $ids = [];
        foreach ($visible as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $events = [];
        $issues = Issue::query()
            ->whereIn('id', $ids)
            ->where('created_on', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_on', '<', $until->format('Y-m-d H:i:s'))
            ->with(['tracker', 'status', 'author'])
            ->orderBy('id')
            ->get();
        foreach ($issues as $issue) {
            $event = $this->issueEvent($issue, $project);
            if ($event instanceof ActivityEvent) {
                $events[] = $event;
            }
        }

        $journals = Journal::query()
            ->where('journalized_type', IssueJournalWriter::JOURNALIZED_ISSUE)
            ->whereIn('journalized_id', $ids)
            ->where('created_on', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_on', '<', $until->format('Y-m-d H:i:s'))
            ->withCount('details')
            ->with(['user'])
            ->orderBy('id')
            ->get();
        $issueRows = Issue::query()->whereIn('id', $ids)->with(['tracker', 'status'])->get()->keyBy('id');
        $canPrivate = $this->permissions->allowed($actor, 'view_private_notes', $project);
        foreach ($journals as $journal) {
            $event = $this->journalEvent($journal, $issueRows->get($journal->journalized_id), $project, $canPrivate);
            if ($event instanceof ActivityEvent) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function issueEvent(Issue $issue, Project $project): ?ActivityEvent
    {
        $author = $issue->author;
        $at = $this->stamp($issue->created_on);
        if (! $author instanceof User || $at === null) {
            return null;
        }

        return new ActivityEvent(
            'issue',
            (int) $issue->id,
            (string) $project->identifier,
            (string) $author->login,
            $this->issueTitle($issue),
            $at,
        );
    }

    private function journalEvent(Journal $journal, mixed $issue, Project $project, bool $canPrivate): ?ActivityEvent
    {
        if (! $issue instanceof Issue) {
            return null;
        }
        $notes = is_string($journal->notes) ? trim($journal->notes) : '';
        $counted = $journal->getAttribute('details_count');
        $details = is_numeric($counted) ? (int) $counted : 0;
        if ($notes === '' && $details === 0) {
            return null;
        }
        if ((bool) $journal->private_notes && $notes !== '' && ! $canPrivate && $details === 0) {
            return null;
        }
        $author = $journal->user;
        $at = $this->stamp($journal->created_on);
        if (! $author instanceof User || $at === null) {
            return null;
        }

        return new ActivityEvent(
            'journal',
            (int) $journal->id,
            (string) $project->identifier,
            (string) $author->login,
            $this->issueTitle($issue),
            $at,
        );
    }

    /**
     * @return list<ActivityEvent>
     */
    private function timeEvents(User $actor, Project $project, Carbon $start, Carbon $until): array
    {
        $query = $this->timeEntries->apply(TimeEntry::query()->where('project_id', $project->id), $actor, $project)
            ->where('created_on', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_on', '<', $until->format('Y-m-d H:i:s'))
            ->with(['user', 'activity'])
            ->orderBy('id');
        $events = [];
        foreach ($query->get() as $entry) {
            $event = $this->timeEvent($entry, $project);
            if ($event instanceof ActivityEvent) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function timeEvent(TimeEntry $entry, Project $project): ?ActivityEvent
    {
        $user = $entry->user;
        $at = $this->stamp($entry->created_on);
        if (! $user instanceof User || $at === null) {
            return null;
        }
        $activity = $entry->activity;
        $activityName = $activity instanceof Enumeration ? (string) $activity->name : '';
        $hours = HourValue::format((float) $entry->hours, 'decimal');
        $title = $hours.' hours ('.$activityName.')';
        if ($entry->issue_id !== null) {
            $title .= ' on #'.$entry->issue_id;
        }

        return new ActivityEvent(
            'time_entry',
            (int) $entry->id,
            (string) $project->identifier,
            (string) $user->login,
            $title,
            $at,
        );
    }

    /**
     * @return list<ActivityEvent>
     */
    private function newsEvents(User $actor, Project $project, Carbon $start, Carbon $until): array
    {
        if (! $this->permissions->allowed($actor, 'view_news', $project)) {
            return [];
        }

        $rows = News::query()
            ->where('project_id', $project->id)
            ->where('created_on', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_on', '<', $until->format('Y-m-d H:i:s'))
            ->with('author')
            ->orderBy('id')
            ->get();
        $events = [];
        foreach ($rows as $news) {
            $author = $news->author;
            $at = $this->stamp($news->created_on);
            if (! $author instanceof User || $at === null) {
                continue;
            }
            $events[] = new ActivityEvent(
                'news',
                (int) $news->id,
                (string) $project->identifier,
                (string) $author->login,
                (string) $news->title,
                $at,
            );
        }

        return $events;
    }

    /**
     * @return list<ActivityEvent>
     */
    private function documentEvents(User $actor, Project $project, Carbon $start, Carbon $until): array
    {
        if (! $this->permissions->allowed($actor, 'view_documents', $project)) {
            return [];
        }

        $rows = Document::query()
            ->where('project_id', $project->id)
            ->where('created_on', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_on', '<', $until->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get();
        $events = [];
        foreach ($rows as $document) {
            $at = $this->stamp($document->created_on);
            if ($at === null) {
                continue;
            }
            $events[] = new ActivityEvent(
                'document',
                (int) $document->id,
                (string) $project->identifier,
                $this->documentAuthor($document),
                'Document: '.$document->title,
                $at,
            );
        }

        return $events;
    }

    /**
     * @return list<ActivityEvent>
     */
    private function fileEvents(User $actor, Project $project, Carbon $start, Carbon $until): array
    {
        if (! $this->permissions->allowed($actor, 'view_files', $project)) {
            return [];
        }

        $versionIds = [];
        foreach (Version::query()->where('project_id', $project->id)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $versionIds[] = (int) $id;
            }
        }

        $rows = Attachment::query()
            ->where(function ($query) use ($project, $versionIds): void {
                $query->where(function ($inner) use ($project): void {
                    $inner->where('container_type', 'Project')->where('container_id', $project->id);
                });
                if ($versionIds !== []) {
                    $query->orWhere(function ($inner) use ($versionIds): void {
                        $inner->where('container_type', 'Version')->whereIn('container_id', $versionIds);
                    });
                }
            })
            ->where('created_on', '>=', $start->format('Y-m-d H:i:s'))
            ->where('created_on', '<', $until->format('Y-m-d H:i:s'))
            ->with('author')
            ->orderBy('id')
            ->get();
        $events = [];
        foreach ($rows as $attachment) {
            $at = $this->stamp($attachment->created_on);
            if ($at === null) {
                continue;
            }
            $author = $attachment->author;
            $events[] = new ActivityEvent(
                'file',
                (int) $attachment->id,
                (string) $project->identifier,
                $author instanceof User ? (string) $author->login : '',
                (string) $attachment->filename,
                $at,
            );
        }

        return $events;
    }

    private function documentAuthor(Document $document): string
    {
        $first = Attachment::query()
            ->where('container_type', 'Document')
            ->where('container_id', $document->id)
            ->with('author')
            ->orderBy('created_on')
            ->orderBy('id')
            ->first();
        if (! $first instanceof Attachment) {
            return '';
        }
        $author = $first->author;

        return $author instanceof User ? (string) $author->login : '';
    }

    private function issueTitle(Issue $issue): string
    {
        $tracker = $issue->tracker;
        $status = $issue->status;
        $trackerName = $tracker instanceof Tracker ? (string) $tracker->name : '';
        $statusName = $status instanceof IssueStatus ? (string) $status->name : '';

        return $trackerName.' #'.$issue->id.' ('.$statusName.'): '.$issue->subject;
    }

    private function stamp(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }

    private function day(?string $from): ?Carbon
    {
        if ($from === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            return null;
        }
        $day = Carbon::createFromFormat('Y-m-d', $from);

        return $day instanceof Carbon ? $day->startOfDay() : null;
    }
}
