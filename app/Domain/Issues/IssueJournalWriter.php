<?php

namespace App\Domain\Issues;

use App\Domain\DomainException;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Reaction;
use App\Models\User;
use DateTimeInterface;

/**
 * Writes issue journals for attribute diffs, notes, relation adds, and
 * later changes to an existing note.
 *
 * Custom-field diffs are not written. A blank note with no attribute diff
 * does not create a row. journalized_type stays the Redmine name Issue.
 */
final class IssueJournalWriter
{
    public const JOURNALIZED_ISSUE = 'Issue';

    public const PROPERTY_ATTR = 'attr';

    public const PROPERTY_RELATION = 'relation';

    /**
     * Redmine STI name stored on reactions that target a journal.
     */
    public const REACTABLE_JOURNAL = 'Journal';

    /**
     * Columns compared on issue update, in journal_details order.
     *
     * @var list<string>
     */
    public const TRACKED_COLUMNS = [
        'status_id',
        'done_ratio',
        'subject',
        'description',
        'priority_id',
        'assigned_to_id',
        'start_date',
        'due_date',
        'estimated_hours',
        'is_private',
        'parent_id',
    ];

    /**
     * @return array<string, string|null>
     */
    public function snapshot(Issue $issue): array
    {
        $values = [];
        foreach (self::TRACKED_COLUMNS as $column) {
            $values[$column] = $this->storageValue($column, $issue->getAttribute($column));
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    public function recordIssueUpdate(
        User $actor,
        Issue $issue,
        array $before,
        array $after,
        ?string $notes,
        bool $privateNotes,
    ): ?Journal {
        $details = [];
        foreach (self::TRACKED_COLUMNS as $column) {
            $old = $before[$column] ?? null;
            $new = $after[$column] ?? null;
            if ($old === $new) {
                continue;
            }
            $details[] = [
                'property' => self::PROPERTY_ATTR,
                'prop_key' => $column,
                'old_value' => $old,
                'value' => $new,
            ];
        }

        if ($details === [] && $notes === null) {
            return null;
        }

        return $this->insert($actor, $issue, $notes, $privateNotes, $details);
    }

    public function recordRelationAdded(User $actor, Issue $issue, Issue $other, string $relationType): Journal
    {
        return $this->insert($actor, $issue, null, false, [[
            'property' => self::PROPERTY_RELATION,
            'prop_key' => $relationType,
            'old_value' => null,
            'value' => (string) $other->id,
        ]]);
    }

    public function recordNote(User $actor, Issue $issue, string $notes, bool $privateNotes): Journal
    {
        return $this->insert($actor, $issue, $notes, $privateNotes, []);
    }

    /**
     * Replaces note text and stamps updated_by_id / updated_on.
     *
     * user_id and created_on stay as they were. private_notes is left alone.
     */
    public function replaceNote(User $editor, Journal $journal, string $notes): Journal
    {
        $journal->timestamps = false;
        $journal->forceFill([
            'notes' => $notes,
            'updated_by_id' => $editor->id,
            'updated_on' => now(),
        ]);
        $journal->save();
        $journal->timestamps = true;
        $journal->refresh();
        $journal->load('details');

        return $journal;
    }

    /**
     * Clears a note. A journal with no details is deleted. A journal that has
     * details keeps those rows and stores notes as null.
     */
    public function removeNote(User $editor, Journal $journal): ?Journal
    {
        if ($journal->details()->exists()) {
            $journal->timestamps = false;
            $journal->forceFill([
                'notes' => null,
                'updated_by_id' => $editor->id,
                'updated_on' => now(),
            ]);
            $journal->save();
            $journal->timestamps = true;
            $journal->refresh();
            $journal->load('details');

            return $journal;
        }

        Reaction::query()
            ->where('reactable_type', self::REACTABLE_JOURNAL)
            ->where('reactable_id', $journal->id)
            ->delete();
        $journal->details()->delete();
        $journal->delete();

        return null;
    }

    /**
     * @param  list<array{property: string, prop_key: string, old_value: string|null, value: string|null}>  $details
     */
    private function insert(User $actor, Issue $issue, ?string $notes, bool $privateNotes, array $details): Journal
    {
        $journal = new Journal;
        $journal->timestamps = false;
        $journal->forceFill([
            'journalized_type' => self::JOURNALIZED_ISSUE,
            'journalized_id' => $issue->id,
            'user_id' => $actor->id,
            'notes' => $notes,
            'private_notes' => $privateNotes,
            'updated_by_id' => null,
            'created_on' => now(),
            'updated_on' => null,
        ]);
        $journal->save();

        foreach ($details as $detail) {
            $journal->details()->create($detail);
        }

        $journal->load('details');

        return $journal;
    }

    private function storageValue(string $column, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        throw new DomainException('Journal snapshot cannot store '.$column.'.');
    }
}
