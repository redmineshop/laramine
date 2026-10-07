<?php

namespace App\Domain\Issues\History;

use App\Domain\Acl\IssueVisibility;
use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatKey;
use App\Domain\Issues\IssueJournalWriter;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;

/**
 * Visible history lines for one journal's details.
 *
 * Custom-field lines use the field name. Values follow the field format.
 * A field the actor cannot see, or a missing field, is omitted. Relation
 * lines are omitted when the other issue is missing or not visible.
 */
final class JournalHistoryLines
{
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
        'tracker_id' => 'Tracker',
        'category_id' => 'Category',
        'fixed_version_id' => 'Target version',
        'project_id' => 'Project',
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
        private readonly JournalDetailFormatter $details,
        private readonly CustomFieldVisibility $fields,
        private readonly IssueVisibility $issues,
    ) {}

    /**
     * @param  iterable<JournalDetail>  $details
     * @return list<JournalPropertyLine>
     */
    public function lines(User $actor, Project $project, iterable $details): array
    {
        $rows = [];
        foreach ($details as $detail) {
            $rows[] = $detail;
        }
        $fields = $this->customFields($rows);
        $lines = [];
        foreach ($rows as $detail) {
            $line = $this->line($actor, $project, $detail, $fields);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  list<JournalDetail>  $details
     * @return array<int, CustomField>
     */
    private function customFields(array $details): array
    {
        $ids = [];
        foreach ($details as $detail) {
            if ((string) $detail->property !== IssueJournalWriter::PROPERTY_CF) {
                continue;
            }
            $id = $this->intId($this->nullableString($detail->prop_key));
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $fields = [];
        foreach (CustomField::query()->with('roles')->whereIn('id', array_values(array_unique($ids)))->get() as $field) {
            $fields[(int) $field->id] = $field;
        }

        return $fields;
    }

    /**
     * @param  array<int, CustomField>  $fields
     */
    private function line(User $actor, Project $project, JournalDetail $detail, array $fields): ?JournalPropertyLine
    {
        $property = (string) $detail->property;

        return match ($property) {
            IssueJournalWriter::PROPERTY_ATTR => $this->attributeLine($detail),
            IssueJournalWriter::PROPERTY_CF => $this->customLine($actor, $project, $detail, $fields),
            IssueJournalWriter::PROPERTY_ATTACHMENT => $this->attachmentLine($detail),
            IssueJournalWriter::PROPERTY_RELATION => $this->relationLine($actor, $detail),
            default => null,
        };
    }

    private function attributeLine(JournalDetail $detail): ?JournalPropertyLine
    {
        $propKey = (string) $detail->prop_key;
        $label = self::ATTRIBUTE_LABELS[$propKey] ?? $propKey;
        $old = $this->nullableString($detail->old_value);
        $new = $this->nullableString($detail->value);
        if ($propKey === 'description') {
            if ($old === null && $new === null) {
                return null;
            }

            return JournalPropertyLine::updated($label);
        }

        return $this->details->attribute(
            $label,
            $this->attributeValue($propKey, $old),
            $this->attributeValue($propKey, $new),
        );
    }

    private function attributeValue(string $propKey, ?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        return match ($propKey) {
            'status_id' => $this->named(IssueStatus::query()->find($this->intId($stored)), $stored),
            'priority_id' => $this->named(Enumeration::query()->find($this->intId($stored)), $stored),
            'assigned_to_id' => $this->person($stored),
            'category_id' => $this->named(IssueCategory::query()->find($this->intId($stored)), $stored),
            'fixed_version_id' => $this->named(Version::query()->find($this->intId($stored)), $stored),
            'tracker_id' => $this->named(Tracker::query()->find($this->intId($stored)), $stored),
            'project_id' => $this->named(Project::query()->find($this->intId($stored)), $stored),
            'parent_id' => $this->parentLabel($stored),
            'is_private' => $this->yesNo($stored),
            default => $stored,
        };
    }

    /**
     * @param  array<int, CustomField>  $fields
     */
    private function customLine(User $actor, Project $project, JournalDetail $detail, array $fields): ?JournalPropertyLine
    {
        $id = $this->intId($this->nullableString($detail->prop_key));
        if ($id === null) {
            return null;
        }
        $field = $fields[$id] ?? null;
        if (! $field instanceof CustomField || ! $this->fields->canSee($actor, $field, $project)) {
            return null;
        }

        $label = (string) $field->name;
        $oldStored = $this->nullableString($detail->old_value);
        $newStored = $this->nullableString($detail->value);
        if ($this->formatKey($field) === FieldFormatKey::Text) {
            if ($oldStored === null && $newStored === null) {
                return null;
            }

            return JournalPropertyLine::updated($label);
        }

        $old = $this->customValue($field, $oldStored);
        $new = $this->customValue($field, $newStored);
        if ($field->multiple && $old === null && $new !== null) {
            return JournalPropertyLine::added($label, $new, true);
        }

        return $this->details->attribute($label, $old, $new);
    }

    private function customValue(CustomField $field, ?string $stored): ?string
    {
        $parts = $this->split($stored);
        if ($parts === []) {
            return null;
        }
        $shown = [];
        foreach ($parts as $part) {
            $shown[] = $this->formatPart($field, $part);
        }

        return implode(', ', $shown);
    }

    private function formatPart(CustomField $field, string $stored): string
    {
        $format = $this->formatKey($field);
        if ($format === null) {
            return $stored;
        }

        return match ($format) {
            FieldFormatKey::String, FieldFormatKey::Link, FieldFormatKey::Int, FieldFormatKey::Float, FieldFormatKey::Date, FieldFormatKey::List => $stored,
            FieldFormatKey::Text => $stored,
            FieldFormatKey::Bool => $this->yesNo($stored),
            FieldFormatKey::Enumeration => $this->enumerationName($field, $stored),
            FieldFormatKey::User => $this->person($stored),
            FieldFormatKey::Version => $this->named(Version::query()->find($this->intId($stored)), $stored),
            FieldFormatKey::Attachment => $this->filename($stored),
            FieldFormatKey::Progressbar => $this->percent($stored),
        };
    }

    private function formatKey(CustomField $field): ?FieldFormatKey
    {
        return FieldFormatKey::tryFrom((string) $field->field_format);
    }

    private function attachmentLine(JournalDetail $detail): ?JournalPropertyLine
    {
        $filename = $this->nullableString($detail->value);
        if ($filename !== null) {
            return JournalPropertyLine::added('File', $filename);
        }
        $removed = $this->nullableString($detail->old_value);
        if ($removed === null) {
            return null;
        }

        return $this->details->attribute('File', $removed, null);
    }

    private function relationLine(User $actor, JournalDetail $detail): ?JournalPropertyLine
    {
        $type = (string) $detail->prop_key;
        $label = self::RELATION_LABELS[$type] ?? $type;
        $newId = $this->nullableString($detail->value);
        if ($newId !== null) {
            $added = $this->relationTarget($actor, $newId);
            if ($added === null) {
                return null;
            }

            return JournalPropertyLine::relationAdded($label, $added['tracker'], $added['id'], $added['subject']);
        }
        $removed = $this->relationTarget($actor, $this->nullableString($detail->old_value));
        if ($removed === null) {
            return null;
        }

        return $this->details->attribute(
            $label,
            $removed['tracker'].' #'.$removed['id'].': '.$removed['subject'],
            null,
        );
    }

    /**
     * @return array{tracker: string, id: int, subject: string}|null
     */
    private function relationTarget(User $actor, ?string $stored): ?array
    {
        $id = $this->intId($stored);
        if ($id === null) {
            return null;
        }
        $issue = Issue::query()->with(['tracker', 'project'])->find($id);
        if (! $issue instanceof Issue || ! $this->issues->canSee($actor, $issue)) {
            return null;
        }
        $tracker = $issue->tracker;
        $trackerName = $tracker instanceof Tracker && (string) $tracker->name !== '' ? (string) $tracker->name : 'Issue';

        return [
            'tracker' => $trackerName,
            'id' => (int) $issue->id,
            'subject' => (string) $issue->subject,
        ];
    }

    private function enumerationName(CustomField $field, string $stored): string
    {
        $id = $this->intId($stored);
        if ($id === null) {
            return $stored;
        }
        $option = CustomFieldEnumeration::query()->find($id);
        if (! $option instanceof CustomFieldEnumeration || (int) $option->custom_field_id !== (int) $field->id) {
            return $stored;
        }
        $name = (string) $option->name;

        return $name !== '' ? $name : $stored;
    }

    private function filename(string $stored): string
    {
        $id = $this->intId($stored);
        if ($id === null) {
            return $stored;
        }
        $file = Attachment::query()->find($id);
        if (! $file instanceof Attachment) {
            return $stored;
        }
        $name = (string) $file->filename;

        return $name !== '' ? $name : $stored;
    }

    private function person(string $stored): string
    {
        $id = $this->intId($stored);
        if ($id === null) {
            return $stored;
        }
        $user = User::query()->find($id);
        if (! $user instanceof User) {
            return $stored;
        }
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);
        if ($name !== '') {
            return $name;
        }
        $login = (string) $user->login;

        return $login !== '' ? $login : $stored;
    }

    private function parentLabel(string $stored): string
    {
        $id = $this->intId($stored);
        if ($id === null) {
            return $stored;
        }

        return '#'.$id;
    }

    private function yesNo(string $stored): string
    {
        return match ($stored) {
            '1' => 'Yes',
            '0' => 'No',
            default => $stored,
        };
    }

    private function percent(string $stored): string
    {
        if (preg_match('/^\d+$/', $stored) !== 1) {
            return $stored;
        }

        return $stored.'%';
    }

    private function named(mixed $row, string $fallback): string
    {
        if (! $row instanceof IssueStatus
            && ! $row instanceof Enumeration
            && ! $row instanceof IssueCategory
            && ! $row instanceof Version
            && ! $row instanceof Tracker
            && ! $row instanceof Project) {
            return $fallback;
        }
        $name = (string) $row->getAttribute('name');

        return $name !== '' ? $name : $fallback;
    }

    /**
     * @return list<string>
     */
    private function split(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }
        $parts = [];
        foreach (explode(',', $stored) as $part) {
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    private function intId(?string $stored): ?int
    {
        if ($stored === null || preg_match('/^[1-9][0-9]*$/', $stored) !== 1) {
            return null;
        }

        return (int) $stored;
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
}
