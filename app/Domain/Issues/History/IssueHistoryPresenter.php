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
 * This is a view model for a later UI. It does not render HTTP and it does
 * not edit or delete journals. Quote, edit, and the more menu are markers.
 */
final class IssueHistoryPresenter
{
    public const SUCCESSFUL_UPDATE = '✓ Successful update.';

    public const TAB_HISTORY = 'History';

    public const TAB_NOTES = 'Notes';

    public const TAB_PROPERTIES = 'Property changes';

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
        [$notes, $properties] = $this->tabEntries($entries);

        return new IssueShowView(
            $entries !== [],
            $this->tabLabels($entries),
            $entries,
            $notes,
            $properties,
            $this->showsNotesFieldset($actor, $issue, $project),
            $this->permissions->allowed($actor, 'set_notes_private', $project),
            false,
            $justUpdated ? self::SUCCESSFUL_UPDATE : null,
            $justUpdated ? 'green' : null,
        );
    }

    /**
     * @param  list<JournalEntryView>  $entries
     * @return list<string>
     */
    private function tabLabels(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $hasNotes = false;
        $hasDetails = false;
        foreach ($entries as $entry) {
            $hasNotes = $hasNotes || $entry->hasNote;
            $hasDetails = $hasDetails || $entry->hasDetails;
        }

        $labels = [self::TAB_HISTORY];
        if ($hasNotes) {
            $labels[] = self::TAB_NOTES;
        }
        if ($hasDetails) {
            $labels[] = self::TAB_PROPERTIES;
        }

        return $labels;
    }

    /**
     * Notes keeps journals with note text. Property changes keeps journals
     * that stored a detail, and drops the note body plus every control except
     * reaction.
     *
     * @param  list<JournalEntryView>  $entries
     * @return array{0: list<JournalEntryView>, 1: list<JournalEntryView>}
     */
    private function tabEntries(array $entries): array
    {
        $notes = [];
        $properties = [];
        foreach ($entries as $entry) {
            if ($entry->hasNote) {
                $notes[] = $entry;
            }
            if ($entry->hasDetails) {
                $properties[] = $this->propertyTabEntry($entry);
            }
        }

        return [$notes, $properties];
    }

    private function propertyTabEntry(JournalEntryView $entry): JournalEntryView
    {
        $actions = [];
        foreach ($entry->actions as $action) {
            if ($action->key === 'reaction') {
                $actions[] = $action;
            }
        }

        return new JournalEntryView(
            $entry->journalId,
            $entry->anchorLabel,
            $entry->anchorHref,
            $entry->hasNote,
            null,
            null,
            $entry->propertyChanges,
            $actions,
            $entry->privateNotes,
            $entry->hasDetails,
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
            $entries[] = $this->entry($actor, $project, $journal, count($entries) + 1);
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

    private function entry(User $actor, Project $project, Journal $journal, int $displayNumber): JournalEntryView
    {
        $noteText = is_string($journal->notes) && trim($journal->notes) !== '' ? $journal->notes : null;
        $hasNote = $noteText !== null;
        $noteHtml = $noteText === null ? null : $this->textile->render($noteText);
        $hasDetails = $journal->details->isNotEmpty();
        $lines = [];
        foreach ($journal->details->sortBy('id') as $detail) {
            $line = $this->line($detail);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $anchorHref = '#note-'.$displayNumber;

        return new JournalEntryView(
            (int) $journal->id,
            '#'.$displayNumber,
            $anchorHref,
            $hasNote,
            $noteText,
            $noteHtml,
            $lines,
            $this->actions->forJournal(
                $hasNote,
                $hasNote && $this->permissions->allowed($actor, 'add_issue_notes', $project),
                $hasNote && $this->canEditJournalNote($actor, $project, $journal),
                $anchorHref,
            ),
            (bool) $journal->private_notes,
            $hasDetails,
        );
    }

    private function canEditJournalNote(User $actor, Project $project, Journal $journal): bool
    {
        if ($this->permissions->allowed($actor, 'edit_issue_notes', $project)) {
            return true;
        }

        return (int) $journal->user_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_issue_notes', $project);
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
