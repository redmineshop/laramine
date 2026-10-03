<?php

namespace App\Domain\Issues\History;

use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\Issues\IssueJournalWriter;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\User;

/**
 * Issue-show history, note rendering, and the private-notes checkbox.
 *
 * This is a view model for a later UI. It does not render HTTP.
 * Notes and Property changes tab contents are not filtered.
 */
final class IssueHistoryPresenter
{
    public const SUCCESSFUL_UPDATE = '✓ Successful update.';

    /**
     * @var list<string>
     */
    public const TAB_LABELS = ['History', 'Notes', 'Property changes'];

    /**
     * @var array<string, string>
     */
    private const ATTRIBUTE_LABELS = [
        'status_id' => 'Status',
        'done_ratio' => '% Done',
        'subject' => 'Subject',
        'description' => 'Description',
        'priority_id' => 'Priority',
        'assigned_to_id' => 'Assignee',
        'start_date' => 'Start date',
        'due_date' => 'Due date',
        'estimated_hours' => 'Estimated time',
        'is_private' => 'Private',
        'parent_id' => 'Parent task',
    ];

    /**
     * @var array<string, string>
     */
    private const RELATION_LABELS = [
        'relates' => 'Related to',
        'blocks' => 'Blocks',
        'duplicates' => 'Duplicates',
        'precedes' => 'Precedes',
        'copied_to' => 'Copied to',
    ];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly JournalDetailFormatter $details,
        private readonly JournalActionList $actions,
        private readonly TextileEmphasis $textile,
    ) {}

    /**
     * @param  bool  $justUpdated  True after IssueService::update returns. Sets the success flash.
     */
    public function present(User $actor, Issue $issue, bool $justUpdated = false): IssueShowView
    {
        $project = $issue->project;
        if ($project === null) {
            throw new DomainException('Issue has no project.');
        }

        $entries = $this->entries($actor, $issue, $project);
        $visible = $entries !== [];

        return new IssueShowView(
            $visible,
            $visible ? self::TAB_LABELS : [],
            $entries,
            $this->showsNotesFieldset($actor, $issue, $project),
            $this->permissions->allowed($actor, 'set_notes_private', $project),
            false,
            $justUpdated ? self::SUCCESSFUL_UPDATE : null,
            $justUpdated ? 'green' : null,
        );
    }

    /**
     * @return list<JournalEntryView>
     */
    private function entries(User $actor, Issue $issue, Project $project): array
    {
        $journals = Journal::query()
            ->where('journalized_type', IssueJournalWriter::JOURNALIZED_ISSUE)
            ->where('journalized_id', $issue->id)
            ->orderBy('id')
            ->with('details')
            ->get();

        $entries = [];
        foreach ($journals as $journal) {
            if (! $this->visible($actor, $project, (bool) $journal->private_notes)) {
                continue;
            }
            $entries[] = $this->entry($journal, count($entries) + 1);
        }

        return $entries;
    }

    private function visible(User $actor, Project $project, bool $privateNotes): bool
    {
        if (! $privateNotes) {
            return true;
        }

        // Same rule as query journal visibility: the author is not exempt.
        return $this->permissions->allowed($actor, 'view_private_notes', $project);
    }

    private function entry(Journal $journal, int $displayNumber): JournalEntryView
    {
        $noteText = is_string($journal->notes) && trim($journal->notes) !== '' ? $journal->notes : null;
        $hasNote = $noteText !== null;
        $noteHtml = $noteText === null ? null : $this->textile->render($noteText);
        $lines = [];
        foreach ($journal->details->sortBy('id') as $detail) {
            $line = $this->line($detail);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return new JournalEntryView(
            (int) $journal->id,
            '#'.$displayNumber,
            $hasNote,
            $noteText,
            $noteHtml,
            $lines,
            $this->actions->forJournal($hasNote),
            (bool) $journal->private_notes,
        );
    }

    private function line(JournalDetail $detail): ?JournalPropertyLine
    {
        if ($detail->property === IssueJournalWriter::PROPERTY_RELATION) {
            return $this->relationLine($detail);
        }
        if ($detail->property !== IssueJournalWriter::PROPERTY_ATTR) {
            return null;
        }

        $propKey = (string) $detail->prop_key;
        $label = self::ATTRIBUTE_LABELS[$propKey] ?? $propKey;

        return $this->details->attribute(
            $label,
            $this->display($propKey, $this->nullableString($detail->old_value)),
            $this->display($propKey, $this->nullableString($detail->value)),
        );
    }

    private function relationLine(JournalDetail $detail): JournalPropertyLine
    {
        $type = (string) $detail->prop_key;
        $label = self::RELATION_LABELS[$type] ?? $type;
        $rawId = $this->nullableString($detail->value);
        $id = is_numeric($rawId) ? (int) $rawId : 0;
        $trackerName = 'Issue';
        $subject = '';
        if ($id > 0) {
            $other = Issue::query()->with('tracker')->find($id);
            if ($other instanceof Issue) {
                $name = $other->tracker?->name;
                $trackerName = is_string($name) && $name !== '' ? $name : $trackerName;
                $subject = (string) $other->subject;
                $id = (int) $other->id;
            }
        }

        return JournalPropertyLine::relationAdded($label, $trackerName, $id, $subject);
    }

    private function display(string $propKey, ?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        return match ($propKey) {
            'status_id' => $this->statusName($stored),
            'priority_id' => $this->priorityName($stored),
            'assigned_to_id' => $this->userName($stored),
            default => $stored,
        };
    }

    private function statusName(string $id): string
    {
        if (! is_numeric($id)) {
            return $id;
        }
        $status = IssueStatus::query()->find((int) $id);

        return $status instanceof IssueStatus ? (string) $status->name : $id;
    }

    private function priorityName(string $id): string
    {
        if (! is_numeric($id)) {
            return $id;
        }
        $priority = Enumeration::query()->find((int) $id);
        if (! $priority instanceof Enumeration || $priority->name === '') {
            return $id;
        }

        return $priority->name;
    }

    private function userName(string $id): string
    {
        if (! is_numeric($id)) {
            return $id;
        }
        $user = User::query()->find((int) $id);
        if (! $user instanceof User) {
            return $id;
        }
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);

        return $name !== '' ? $name : (string) $user->login;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    private function showsNotesFieldset(User $actor, Issue $issue, Project $project): bool
    {
        if ($this->permissions->allowed($actor, 'add_issue_notes', $project)) {
            return true;
        }
        if ($this->permissions->allowed($actor, 'edit_issues', $project)) {
            return true;
        }

        return (int) $issue->author_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_issues', $project);
    }
}
