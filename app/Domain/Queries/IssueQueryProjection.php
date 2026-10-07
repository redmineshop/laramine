<?php

namespace App\Domain\Queries;

use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatKey;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;

/**
 * Selects the columns a query result exposes and fills their plain-text cells.
 *
 * A null column list is the display default. Unknown names and custom fields
 * the actor cannot see are omitted. Repeated names keep the first one.
 * `spent_hours` uses the same visibility-aware per-issue sum as query totals.
 */
final class IssueQueryProjection
{
    public function __construct(
        private readonly CustomFieldVisibility $visibility,
        private readonly SpentHoursQuery $spentHours,
    ) {}

    /**
     * @param  list<string>|null  $stored
     * @return list<string>
     */
    public function names(?User $actor, ?Project $project, ?array $stored): array
    {
        $requested = $stored ?? IssueQueryColumns::DEFAULT;
        $builtin = array_fill_keys(IssueQueryColumns::AVAILABLE, true);
        $names = [];
        $seen = [];
        foreach ($requested as $name) {
            if (isset($seen[$name]) || ! $this->available($name, $actor, $project, $builtin)) {
                continue;
            }
            $seen[$name] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  iterable<Issue>  $issues
     * @param  list<string>  $columns
     * @return list<IssueQueryRow>
     */
    public function rows(?User $actor, iterable $issues, array $columns): array
    {
        $list = [];
        foreach ($issues as $issue) {
            $list[] = $issue;
        }

        if ($list === [] || $columns === []) {
            $empty = [];
            foreach ($list as $issue) {
                $empty[] = new IssueQueryRow((int) $issue->id, []);
            }

            return $empty;
        }

        $need = array_fill_keys($columns, true);
        $context = $this->context($actor, $list, $need, $columns);
        $rows = [];
        foreach ($list as $issue) {
            $values = [];
            foreach ($columns as $column) {
                $values[$column] = $this->cell($issue, $column, $context);
            }
            $rows[] = new IssueQueryRow((int) $issue->id, $values);
        }

        return $rows;
    }

    /**
     * @param  array<string, true>  $builtin
     */
    private function available(string $name, ?User $actor, ?Project $project, array $builtin): bool
    {
        if (isset($builtin[$name])) {
            return true;
        }

        if (preg_match('/^cf_(\d+)$/', $name, $matches) !== 1) {
            return false;
        }

        $customField = CustomField::query()->with('roles')->find((int) $matches[1]);
        if (! $customField instanceof CustomField || $customField->type !== 'IssueCustomField') {
            return false;
        }

        return $this->visibility->canSee($actor, $customField, $project);
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array<string, true>  $need
     * @param  list<string>  $columns
     * @return array{
     *     projects: array<int, string>,
     *     trackers: array<int, string>,
     *     statuses: array<int, string>,
     *     priorities: array<int, string>,
     *     users: array<int, string>,
     *     categories: array<int, string>,
     *     versions: array<int, string>,
     *     spent: array<int, string>,
     *     custom: array<int, array<string, string|null>>
     * }
     */
    private function context(?User $actor, array $issues, array $need, array $columns): array
    {
        $issueIds = [];
        foreach ($issues as $issue) {
            $issueIds[] = (int) $issue->id;
        }

        $spent = [];
        if (isset($need['spent_hours']) && $issueIds !== []) {
            $spent = $this->spentHours->perIssue(Issue::query()->whereIn('issues.id', $issueIds), $actor);
        }

        $categoryIds = $this->ids($issues, 'category_id');

        return [
            'projects' => isset($need['project']) ? $this->nameMap(Project::query()->whereIn('id', $this->ids($issues, 'project_id'))->get()) : [],
            'trackers' => isset($need['tracker']) ? $this->nameMap(Tracker::query()->whereIn('id', $this->ids($issues, 'tracker_id'))->get()) : [],
            'statuses' => isset($need['status']) ? $this->nameMap(IssueStatus::query()->whereIn('id', $this->ids($issues, 'status_id'))->get()) : [],
            'priorities' => isset($need['priority']) ? $this->nameMap(Enumeration::query()->whereIn('id', $this->ids($issues, 'priority_id'))->get()) : [],
            'users' => isset($need['author']) || isset($need['assigned_to'])
                ? $this->userNames(array_values(array_unique([...$this->ids($issues, 'author_id'), ...$this->ids($issues, 'assigned_to_id')])))
                : [],
            'categories' => isset($need['category']) && $categoryIds !== []
                ? $this->nameMap(IssueCategory::query()->whereIn('id', $categoryIds)->get())
                : [],
            'versions' => isset($need['fixed_version']) ? $this->versionNames($this->ids($issues, 'fixed_version_id')) : [],
            'spent' => $spent,
            'custom' => $this->customCells($issues, $columns),
        ];
    }

    /**
     * @param  array{
     *     projects: array<int, string>,
     *     trackers: array<int, string>,
     *     statuses: array<int, string>,
     *     priorities: array<int, string>,
     *     users: array<int, string>,
     *     categories: array<int, string>,
     *     versions: array<int, string>,
     *     spent: array<int, string>,
     *     custom: array<int, array<string, string|null>>
     * }  $context
     */
    private function cell(Issue $issue, string $column, array $context): ?string
    {
        if (str_starts_with($column, 'cf_')) {
            return $context['custom'][(int) $issue->id][$column] ?? null;
        }

        return match ($column) {
            'id' => (string) $issue->id,
            'project' => $context['projects'][(int) $issue->project_id] ?? null,
            'tracker' => $context['trackers'][(int) $issue->tracker_id] ?? null,
            'parent' => $issue->parent_id === null ? null : (string) $issue->parent_id,
            'status' => $context['statuses'][(int) $issue->status_id] ?? null,
            'priority' => $context['priorities'][(int) $issue->priority_id] ?? null,
            'subject' => (string) $issue->subject,
            'author' => $context['users'][(int) $issue->author_id] ?? null,
            'assigned_to' => $issue->assigned_to_id === null ? null : ($context['users'][(int) $issue->assigned_to_id] ?? null),
            'updated_on' => $this->clock($issue, 'updated_on', 19),
            'category' => $issue->category_id === null ? null : ($context['categories'][(int) $issue->category_id] ?? null),
            'fixed_version' => $issue->fixed_version_id === null ? null : ($context['versions'][(int) $issue->fixed_version_id] ?? null),
            'start_date' => $this->clock($issue, 'start_date', 10),
            'due_date' => $this->clock($issue, 'due_date', 10),
            'estimated_hours' => $this->hours($issue),
            'spent_hours' => $context['spent'][(int) $issue->id] ?? '0',
            'done_ratio' => $this->whole($issue, 'done_ratio'),
            'created_on' => $this->clock($issue, 'created_on', 19),
            'closed_on' => $this->clock($issue, 'closed_on', 19),
            'is_private' => $this->flag($issue),
            'description' => $this->text($issue->getAttribute('description')),
            default => null,
        };
    }

    /**
     * @param  list<Issue>  $issues
     * @param  list<string>  $columns
     * @return array<int, array<string, string|null>>
     */
    private function customCells(array $issues, array $columns): array
    {
        /** @var array<int, string> $names */
        $names = [];
        foreach ($columns as $column) {
            if (preg_match('/^cf_(\d+)$/', $column, $matches) === 1) {
                $names[(int) $matches[1]] = $column;
            }
        }
        if ($names === [] || $issues === []) {
            return [];
        }

        $issueIds = [];
        foreach ($issues as $issue) {
            $issueIds[] = (int) $issue->id;
        }
        $fieldIds = array_keys($names);
        $fields = [];
        foreach (CustomField::query()->whereIn('id', $fieldIds)->get() as $field) {
            $fields[(int) $field->id] = $field;
        }

        /** @var array<int, array<int, list<string>>> $grouped */
        $grouped = [];
        $enumIds = [];
        $userIds = [];
        $versionIds = [];
        $values = CustomValue::query()
            ->where('customized_type', 'Issue')
            ->whereIn('customized_id', $issueIds)
            ->whereIn('custom_field_id', $fieldIds)
            ->orderBy('id')
            ->get();
        foreach ($values as $value) {
            $text = $this->text($value->getAttribute('value'));
            if ($text === null) {
                continue;
            }
            $issueId = (int) $value->customized_id;
            $fieldId = (int) $value->custom_field_id;
            $grouped[$issueId][$fieldId][] = $text;
            $format = $this->format($fields[$fieldId] ?? null);
            if ($format === null || preg_match('/^[0-9]+$/', $text) !== 1) {
                continue;
            }
            $id = (int) $text;
            if ($format === FieldFormatKey::Enumeration) {
                $enumIds[] = $id;
            } elseif ($format === FieldFormatKey::User) {
                $userIds[] = $id;
            } elseif ($format === FieldFormatKey::Version) {
                $versionIds[] = $id;
            }
        }

        $enums = $this->enumerationNames($enumIds);
        $users = $this->userNames($userIds);
        $versions = $this->versionNames($versionIds);
        $cells = [];
        foreach ($issueIds as $issueId) {
            foreach ($names as $fieldId => $column) {
                $parts = $grouped[$issueId][$fieldId] ?? [];
                if ($parts === []) {
                    $cells[$issueId][$column] = null;

                    continue;
                }
                $format = $this->format($fields[$fieldId] ?? null);
                $shown = [];
                foreach ($parts as $part) {
                    $shown[] = $this->customText($format, $fieldId, $part, $enums, $users, $versions);
                }
                $cells[$issueId][$column] = implode(', ', $shown);
            }
        }

        return $cells;
    }

    /**
     * @param  array<int, array{field: int, name: string}>  $enums
     * @param  array<int, string>  $users
     * @param  array<int, string>  $versions
     */
    private function customText(?FieldFormatKey $format, int $fieldId, string $stored, array $enums, array $users, array $versions): string
    {
        if ($format === null || preg_match('/^[0-9]+$/', $stored) !== 1) {
            return $stored;
        }

        $id = (int) $stored;

        return match ($format) {
            FieldFormatKey::Enumeration => isset($enums[$id]) && $enums[$id]['field'] === $fieldId ? $enums[$id]['name'] : $stored,
            FieldFormatKey::User => $users[$id] ?? $stored,
            FieldFormatKey::Version => $versions[$id] ?? $stored,
            FieldFormatKey::String, FieldFormatKey::Text, FieldFormatKey::Link, FieldFormatKey::Int, FieldFormatKey::Float, FieldFormatKey::Date, FieldFormatKey::List, FieldFormatKey::Bool, FieldFormatKey::Attachment, FieldFormatKey::Progressbar => $stored,
        };
    }

    private function format(?CustomField $field): ?FieldFormatKey
    {
        if (! $field instanceof CustomField) {
            return null;
        }

        return FieldFormatKey::tryFrom((string) $field->field_format);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{field: int, name: string}>
     */
    private function enumerationNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (CustomFieldEnumeration::query()->whereIn('id', array_values(array_unique($ids)))->get() as $row) {
            $map[(int) $row->id] = [
                'field' => (int) $row->custom_field_id,
                'name' => (string) $row->name,
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (User::query()->whereIn('id', array_values(array_unique($ids)))->get() as $user) {
            $map[(int) $user->id] = $this->personName($user);
        }

        return $map;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function versionNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->nameMap(Version::query()->whereIn('id', array_values(array_unique($ids)))->get());
    }

    /**
     * @param  iterable<Model>  $rows
     * @return array<int, string>
     */
    private function nameMap(iterable $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->getAttribute('id')] = (string) $row->getAttribute('name');
        }

        return $map;
    }

    /**
     * @param  list<Issue>  $issues
     * @return list<int>
     */
    private function ids(array $issues, string $attribute): array
    {
        $ids = [];
        foreach ($issues as $issue) {
            $raw = $issue->getAttribute($attribute);
            if (is_numeric($raw)) {
                $ids[] = (int) $raw;
            }
        }

        return array_values(array_unique($ids));
    }

    private function personName(User $user): string
    {
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);
        if ($name !== '') {
            return $name;
        }

        $login = (string) $user->login;

        return $login !== '' ? $login : (string) $user->id;
    }

    private function clock(Issue $issue, string $column, int $length): ?string
    {
        $raw = $issue->getRawOriginal($column);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return substr($raw, 0, $length);
    }

    private function hours(Issue $issue): ?string
    {
        $raw = $issue->getRawOriginal('estimated_hours');
        if ($raw === null || $raw === '') {
            return null;
        }

        return PlainDecimal::text($raw);
    }

    private function whole(Issue $issue, string $column): ?string
    {
        $raw = $issue->getRawOriginal($column);
        if (! is_numeric($raw)) {
            return null;
        }

        return (string) (int) $raw;
    }

    private function flag(Issue $issue): string
    {
        $raw = $issue->getRawOriginal('is_private');

        return $raw === true || $raw === 1 || $raw === '1' ? '1' : '0';
    }

    private function text(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return PlainDecimal::text($value);
        }

        return null;
    }
}
