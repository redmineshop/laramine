<?php

namespace App\Domain\Issues;

use App\Domain\DomainException;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Reaction;
use App\Models\User;
use DateTimeInterface;

/**
 * Writes issue journals for attribute diffs, custom-value diffs, notes,
 * relation adds, and later changes to an existing note.
 *
 * Custom-value diffs use `property = cf` and `prop_key` the custom field id.
 * Multiple stored values are joined with a comma in row id order. A blank
 * note with no attribute or custom-value diff does not create a row.
 * journalized_type stays the Redmine name Issue.
 */
final class IssueJournalWriter
{
    public const JOURNALIZED_ISSUE = 'Issue';

    public const PROPERTY_ATTR = 'attr';

    public const PROPERTY_CF = 'cf';

    public const PROPERTY_RELATION = 'relation';

    public const PROPERTY_ATTACHMENT = 'attachment';

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
     * Stored custom-value strings for one issue, keyed by custom field id,
     * in `custom_values.id` order. Blank values are omitted.
     *
     * @return array<int, list<string>>
     */
    public function customSnapshot(Issue $issue): array
    {
        /** @var array<int, list<string>> $grouped */
        $grouped = [];
        $rows = CustomValue::query()
            ->where('customized_type', self::JOURNALIZED_ISSUE)
            ->where('customized_id', $issue->id)
            ->orderBy('id')
            ->get(['custom_field_id', 'value']);
        foreach ($rows as $row) {
            $value = $row->value;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $fieldId = (int) $row->custom_field_id;
            $grouped[$fieldId] ??= [];
            $grouped[$fieldId][] = $value;
        }

        return $grouped;
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @param  array<int, list<string>>  $customBefore
     * @param  array<int, list<string>>  $customAfter
     */
    public function recordIssueUpdate(
        User $actor,
        Issue $issue,
        array $before,
        array $after,
        ?string $notes,
        bool $privateNotes,
        array $customBefore = [],
        array $customAfter = [],
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

        foreach ($this->customDetails($customBefore, $customAfter) as $detail) {
            $details[] = $detail;
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

    /**
     * @param  array<int, list<string>>  $before
     * @param  array<int, list<string>>  $after
     * @return list<array{property: string, prop_key: string, old_value: string|null, value: string|null}>
     */
    private function customDetails(array $before, array $after): array
    {
        $ids = [];
        foreach (array_merge(array_keys($before), array_keys($after)) as $id) {
            $ids[(int) $id] = (int) $id;
        }
        if ($ids === []) {
            return [];
        }

        $fields = CustomField::query()
            ->whereIn('id', array_values($ids))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $ordered = [];
        foreach ($fields as $field) {
            $ordered[] = (int) $field->id;
        }
        $missing = [];
        foreach ($ids as $id) {
            if (! in_array($id, $ordered, true)) {
                $missing[] = $id;
            }
        }
        sort($missing);

        $details = [];
        foreach (array_merge($ordered, $missing) as $id) {
            $old = $this->joined($before[$id] ?? []);
            $new = $this->joined($after[$id] ?? []);
            if ($old === $new) {
                continue;
            }
            $details[] = [
                'property' => self::PROPERTY_CF,
                'prop_key' => (string) $id,
                'old_value' => $old,
                'value' => $new,
            ];
        }

        return $details;
    }

    /**
     * @param  list<string>  $values
     */
    private function joined(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return implode(',', $values);
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
