<?php

namespace App\Domain\Queries;

use App\Domain\Acl\PermissionService;
use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatKey;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

/**
 * Project custom fields on `ProjectQuery` and `ProjectAdminQuery`.
 *
 * A field is a filter only when `is_filter` is set. Columns, sort, and totals
 * do not require that flag. Int and float are the totalable formats. A hidden
 * field is omitted from columns and rejected for a filter, sort, or total.
 * A cell stays blank when the actor cannot see the field on that project.
 * A filter, sort key, and total use that same per-project check, so a stored
 * value on a project where the actor lacks the role does not match.
 * History operators read `journalized_type` Project. This tree does not write
 * those journals, so `cf` matches nothing until one exists.
 */
final class ProjectQueryFields
{
    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldVisibility $visibility,
        private readonly PermissionService $permissions,
    ) {}

    public function assertFilter(QueryFilter $filter, ?User $actor, bool $admin): void
    {
        if (! str_starts_with($filter->field, 'cf_')) {
            $this->assertBuiltin($filter, $admin);

            return;
        }

        $field = $this->require($filter->field, $actor, true);
        $format = $this->formats->get((string) $field->field_format);
        OperatorMatrix::assert($format->queryFilterType(), $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
        $this->assertValues($field, $filter, $actor, $format->queryFilterType());
    }

    public function matches(?User $actor, Project $project, QueryFilter $filter): bool
    {
        if (! str_starts_with($filter->field, 'cf_')) {
            return true;
        }

        $field = $this->require($filter->field, $actor, true);
        $operator = $filter->operator;
        $negative = in_array($operator, ['!', '!~', '!*', '!ev'], true);
        $positive = match ($operator) {
            '!' => '=',
            '!~' => '~',
            '!*' => '*',
            '!ev' => 'ev',
            default => $operator,
        };
        $hit = $this->positive($actor, $project, $field, $filter, $positive);

        return $negative ? ! $hit : $hit;
    }

    /**
     * @param  list<string>|null  $requested
     * @param  list<string>  $builtin
     * @param  list<string>  $defaults
     * @return list<string>
     */
    public function columns(?User $actor, ?array $requested, array $builtin, array $defaults): array
    {
        $names = [];
        foreach ($requested ?? $defaults as $name) {
            if (in_array($name, $names, true)) {
                continue;
            }
            if (in_array($name, $builtin, true) || $this->columnAvailable($actor, $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function columnAvailable(?User $actor, string $name): bool
    {
        $field = $this->find($name);
        if (! $field instanceof CustomField) {
            return false;
        }

        return $this->visibility->canSee($actor, $field, null);
    }

    public function cell(?User $actor, Project $project, string $name): ?string
    {
        $field = $this->find($name);
        if (! $field instanceof CustomField || ! $this->visibility->canSee($actor, $field, $project)) {
            return null;
        }

        $stored = $this->stored($project, (int) $field->id);
        if ($stored === []) {
            return null;
        }

        $shown = [];
        foreach ($stored as $value) {
            $shown[] = $this->display($field, $value);
        }

        return implode(', ', $shown);
    }

    /**
     * @param  list<string>  $builtin
     */
    public function assertSort(string $column, string $direction, ?User $actor, array $builtin): void
    {
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new QueryValidationException('Sort direction is invalid.');
        }
        if (in_array($column, $builtin, true)) {
            return;
        }

        $field = $this->find($column);
        if (! $field instanceof CustomField) {
            throw new QueryValidationException('Sort column is not available: '.$column.'.');
        }
        if (! $this->visibility->canSee($actor, $field, null)) {
            throw new QueryValidationException('Custom field is not visible: '.$column.'.');
        }
        $key = FieldFormatKey::tryFrom((string) $field->field_format);
        if ($key === null || $key === FieldFormatKey::Attachment) {
            throw new QueryValidationException('Custom field is not sortable: '.$column.'.');
        }
    }

    public function sortKey(?User $actor, Project $project, string $column): string
    {
        $field = $this->find($column);
        if (! $field instanceof CustomField || ! $this->visibility->canSee($actor, $field, $project)) {
            return '';
        }

        $stored = $this->stored($project, (int) $field->id);
        if ($stored === []) {
            return '';
        }

        $key = FieldFormatKey::tryFrom((string) $field->field_format);

        return match ($key) {
            FieldFormatKey::Int, FieldFormatKey::Progressbar => $this->numericKey($stored, true),
            FieldFormatKey::Float => $this->numericKey($stored, false),
            FieldFormatKey::Enumeration => $this->enumerationKey((int) $field->id, $stored),
            FieldFormatKey::User => $this->userKey($stored),
            FieldFormatKey::Version => $this->versionKey($stored),
            FieldFormatKey::String, FieldFormatKey::Text, FieldFormatKey::Link, FieldFormatKey::Date, FieldFormatKey::List, FieldFormatKey::Bool => $this->textKey($stored),
            FieldFormatKey::Attachment, null => '',
        };
    }

    /**
     * @param  list<Project>  $projects
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public function totals(?User $actor, array $projects, array $names): array
    {
        $totals = [];
        $seen = [];
        foreach ($names as $name) {
            if (isset($seen[$name])) {
                throw new QueryValidationException('Total column is repeated: '.$name.'.');
            }
            $seen[$name] = true;
            $field = $this->require($name, $actor, false);
            $format = $this->formats->get((string) $field->field_format);
            if (! $format->supportsTotal()) {
                throw new QueryValidationException('Custom field is not totalable: '.$name.'.');
            }
            $integer = $format->key() === 'int';
            $sum = 0.0;
            foreach ($projects as $project) {
                if (! $this->visibility->canSee($actor, $field, $project)) {
                    continue;
                }
                foreach ($this->stored($project, (int) $field->id) as $value) {
                    if ($this->numeric($value, $integer) === null) {
                        continue;
                    }
                    $sum += (float) $value;
                }
            }
            $totals[$name] = PlainDecimal::text($sum);
        }

        return $totals;
    }

    private function assertBuiltin(QueryFilter $filter, bool $admin): void
    {
        $type = match ($filter->field) {
            'status', 'id', 'is_public' => 'list',
            'parent_id' => 'list_subprojects',
            'name', 'description' => 'text',
            'created_on', 'updated_on' => 'date_past',
            default => throw new QueryValidationException('Filter field is not available: '.$filter->field.'.'),
        };
        if ($filter->field === 'status') {
            foreach ($filter->values as $value) {
                $allowed = $admin ? ['1', '5', '9', '10'] : ['1', '5'];
                if (! in_array($value, $allowed, true)) {
                    throw new QueryValidationException('Project status is not available: '.$value.'.');
                }
            }
        }
        OperatorMatrix::assert($type, $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
    }

    private function assertValues(CustomField $field, QueryFilter $filter, ?User $actor, string $filterType): void
    {
        $operator = $filter->operator;
        if (in_array($operator, ['*', '!*'], true)) {
            return;
        }
        if ($filterType === 'string' || $filterType === 'text') {
            if (in_array($operator, ['~', '!~', '*~'], true)) {
                FilterValues::tokens($filter);
            }

            return;
        }
        if (in_array($filterType, ['integer', 'float', 'hour'], true)) {
            if (in_array($operator, ['=', '>=', '<=', '><'], true)) {
                $filterType === 'integer' ? FilterValues::integers($filter) : FilterValues::decimals($filter);
            }

            return;
        }
        if ($filterType === 'date' || $filterType === 'date_past') {
            if (in_array($operator, ['=', '>=', '<=', '><'], true)) {
                FilterValues::dates($filter);
            }
            if (in_array($operator, DateWindow::OFFSET_OPERATORS, true)) {
                FilterValues::dayOffset($filter);
            }

            return;
        }
        if ((string) $field->field_format === 'user') {
            FilterValues::ids($filter, $actor, true);

            return;
        }
        if (in_array('me', FilterValues::present($filter), true)) {
            throw new QueryValidationException('Filter value me is not valid for '.$filter->field.'.');
        }
    }

    private function positive(?User $actor, Project $project, CustomField $field, QueryFilter $filter, string $operator): bool
    {
        if (! $this->visibility->canSee($actor, $field, $project)) {
            if ($operator === 'ev' || $operator === 'cf') {
                return false;
            }
            $stored = [];
        } else {
            $stored = $this->stored($project, (int) $field->id);
        }
        $format = $this->formats->get((string) $field->field_format);
        $filterType = $format->queryFilterType();

        if ($operator === '*') {
            return $stored !== [];
        }
        if ($operator === 'ev') {
            return $this->listHit($field, $filter, $actor, $stored) || $this->historyHit($actor, $project, $field, $filter, false);
        }
        if ($operator === 'cf') {
            return $this->historyHit($actor, $project, $field, $filter, true);
        }
        if ($filterType === 'string' || $filterType === 'text') {
            return $this->textHit($stored, $filter, $operator);
        }
        if (in_array($filterType, ['integer', 'float', 'hour'], true)) {
            return $this->numberHit($stored, $filter, $operator, $filterType === 'integer');
        }
        if ($filterType === 'date' || $filterType === 'date_past') {
            return $stored !== [] && $this->dateHit($project, $field, $filter, $operator, $actor);
        }

        return $this->listHit($field, $filter, $actor, $stored);
    }

    /**
     * @param  list<string>  $stored
     */
    private function textHit(array $stored, QueryFilter $filter, string $operator): bool
    {
        $needles = [];
        foreach (FilterValues::present($filter) as $value) {
            $needles[] = mb_strtolower($value);
        }
        foreach ($stored as $value) {
            $text = mb_strtolower($value);
            if ($operator === '=' && in_array($text, $needles, true)) {
                return true;
            }
            if ($operator === '^' || $operator === '$') {
                foreach ($needles as $needle) {
                    if ($needle !== '' && ($operator === '^' ? str_starts_with($text, $needle) : str_ends_with($text, $needle))) {
                        return true;
                    }
                }
            }
        }
        if ($operator === '~' || $operator === '*~') {
            $tokens = FilterValues::tokens($filter);
            if ($operator === '*~') {
                foreach ($stored as $value) {
                    $text = mb_strtolower($value);
                    foreach ($tokens as $token) {
                        if (str_contains($text, mb_strtolower($token))) {
                            return true;
                        }
                    }
                }

                return false;
            }
            foreach ($stored as $value) {
                $text = mb_strtolower($value);
                $all = true;
                foreach ($tokens as $token) {
                    if (! str_contains($text, mb_strtolower($token))) {
                        $all = false;
                    }
                }
                if ($all) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $stored
     */
    private function numberHit(array $stored, QueryFilter $filter, string $operator, bool $integer): bool
    {
        $numbers = $integer ? FilterValues::integers($filter) : FilterValues::decimals($filter);
        $values = [];
        foreach ($stored as $value) {
            $number = $this->numeric($value, $integer);
            if ($number !== null) {
                $values[] = (float) $number;
            }
        }
        if ($values === []) {
            return false;
        }
        if ($operator === '=') {
            foreach ($values as $value) {
                foreach ($numbers as $number) {
                    if (abs($value - (float) $number) < 0.0000001) {
                        return true;
                    }
                }
            }

            return false;
        }
        $bound = (float) $numbers[0];
        foreach ($values as $value) {
            $hit = match ($operator) {
                '>=' => $value >= $bound,
                '<=' => $value <= $bound,
                '><' => $value >= $bound && $value <= (float) $numbers[1],
                default => false,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    private function dateHit(Project $project, CustomField $field, QueryFilter $filter, string $operator, ?User $actor): bool
    {
        $query = DB::table('custom_values')
            ->where('customized_type', 'Project')
            ->where('customized_id', $project->id)
            ->where('custom_field_id', $field->id)
            ->whereRaw("custom_values.value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'");
        $dates = DateWindow::forUser($actor);
        if (in_array($operator, DateWindow::CLOSED_RELATIVE, true) || in_array($operator, DateWindow::OFFSET_OPERATORS, true)) {
            $days = in_array($operator, DateWindow::OFFSET_OPERATORS, true) ? FilterValues::dayOffset($filter) : 0;
            $dates->calendarBound($operator, $days)->apply($query, 'custom_values.value');

            return $query->exists();
        }
        $values = FilterValues::dates($filter);
        if ($operator === '=') {
            $query->whereIn('custom_values.value', $values);
        } elseif ($operator === '>=') {
            $query->where('custom_values.value', '>=', $values[0]);
        } elseif ($operator === '<=') {
            $query->where('custom_values.value', '<=', $values[0]);
        } elseif ($operator === '><') {
            $query->whereBetween('custom_values.value', [$values[0], $values[1]]);
        } else {
            return false;
        }

        return $query->exists();
    }

    /**
     * @param  list<string>  $stored
     */
    private function listHit(CustomField $field, QueryFilter $filter, ?User $actor, array $stored): bool
    {
        if ((string) $field->field_format === 'user') {
            $wanted = [];
            foreach (FilterValues::ids($filter, $actor, true) as $id) {
                $wanted[] = (string) $id;
            }
        } else {
            $wanted = FilterValues::present($filter);
        }
        foreach ($stored as $value) {
            if (in_array($value, $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    private function historyHit(?User $actor, Project $project, CustomField $field, QueryFilter $filter, bool $oldOnly): bool
    {
        $wanted = (string) $field->field_format === 'user'
            ? array_map(strval(...), FilterValues::ids($filter, $actor, true))
            : FilterValues::present($filter);
        $canPrivate = $this->permissions->allowed($actor, 'view_private_notes', $project);
        $journals = Journal::query()
            ->where('journalized_type', 'Project')
            ->where('journalized_id', $project->id)
            ->with('details')
            ->get();
        foreach ($journals as $journal) {
            if ($journal->private_notes && ! $canPrivate) {
                continue;
            }
            foreach ($journal->details as $detail) {
                if ((string) $detail->property !== 'cf' || (string) $detail->prop_key !== (string) $field->id) {
                    continue;
                }
                $sides = $oldOnly ? [$detail->old_value] : [$detail->old_value, $detail->value];
                foreach ($sides as $side) {
                    if (is_string($side) && in_array($side, $wanted, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function require(string $name, ?User $actor, bool $filter): CustomField
    {
        $field = $this->find($name);
        if (! $field instanceof CustomField) {
            throw new QueryValidationException('Custom field filter is unknown: '.$name.'.');
        }
        if ($filter && ! $field->is_filter) {
            throw new QueryValidationException('Custom field is not a filter: '.$name.'.');
        }
        if (! $this->visibility->canSee($actor, $field, null)) {
            throw new QueryValidationException('Custom field is not visible: '.$name.'.');
        }

        return $field;
    }

    private function find(string $name): ?CustomField
    {
        if (preg_match('/^cf_(\d+)$/', $name, $matches) !== 1) {
            return null;
        }
        $field = CustomField::query()->with('roles')->find((int) $matches[1]);
        if (! $field instanceof CustomField || $field->type !== 'ProjectCustomField') {
            return null;
        }

        return $field;
    }

    /**
     * @return list<string>
     */
    private function stored(Project $project, int $fieldId): array
    {
        $values = [];
        $rows = CustomValue::query()
            ->where('customized_type', 'Project')
            ->where('customized_id', $project->id)
            ->where('custom_field_id', $fieldId)
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $text = $row->getAttribute('value');
            if (is_string($text) && $text !== '') {
                $values[] = $text;
            }
        }

        return $values;
    }

    private function display(CustomField $field, string $stored): string
    {
        $format = FieldFormatKey::tryFrom((string) $field->field_format);
        if ($format === null || preg_match('/^[0-9]+$/', $stored) !== 1) {
            return $stored;
        }
        $id = (int) $stored;

        return match ($format) {
            FieldFormatKey::Enumeration => $this->enumerationName((int) $field->id, $id) ?? $stored,
            FieldFormatKey::User => $this->personName($id) ?? $stored,
            FieldFormatKey::Version => $this->versionName($id) ?? $stored,
            FieldFormatKey::String, FieldFormatKey::Text, FieldFormatKey::Link, FieldFormatKey::Int, FieldFormatKey::Float, FieldFormatKey::Date, FieldFormatKey::List, FieldFormatKey::Bool, FieldFormatKey::Attachment, FieldFormatKey::Progressbar => $stored,
        };
    }

    private function enumerationName(int $fieldId, int $id): ?string
    {
        $row = CustomFieldEnumeration::query()->find($id);
        if (! $row instanceof CustomFieldEnumeration || (int) $row->custom_field_id !== $fieldId) {
            return null;
        }

        return (string) $row->name;
    }

    private function personName(int $id): ?string
    {
        $user = User::query()->find($id);
        if (! $user instanceof User) {
            return null;
        }
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);
        if ($name !== '') {
            return $name;
        }
        $login = (string) $user->login;

        return $login !== '' ? $login : (string) $user->id;
    }

    private function versionName(int $id): ?string
    {
        $version = Version::query()->find($id);

        return $version instanceof Version ? (string) $version->name : null;
    }

    /**
     * @param  list<string>  $stored
     */
    private function textKey(array $stored): string
    {
        $min = null;
        foreach ($stored as $value) {
            $text = mb_strtolower($value);
            if ($min === null || $text < $min) {
                $min = $text;
            }
        }

        return $min ?? '';
    }

    /**
     * @param  list<string>  $stored
     */
    private function numericKey(array $stored, bool $integer): string
    {
        $min = null;
        foreach ($stored as $value) {
            $number = $this->numeric($value, $integer);
            if ($number === null) {
                continue;
            }
            $float = (float) $number;
            if ($min === null || $float < $min) {
                $min = $float;
            }
        }
        if ($min === null) {
            return '';
        }

        return sprintf('%020.6f', $min);
    }

    /**
     * @param  list<string>  $stored
     */
    private function enumerationKey(int $fieldId, array $stored): string
    {
        $min = null;
        foreach ($stored as $value) {
            if (preg_match('/^[0-9]+$/', $value) !== 1) {
                continue;
            }
            $row = CustomFieldEnumeration::query()->find((int) $value);
            if (! $row instanceof CustomFieldEnumeration || (int) $row->custom_field_id !== $fieldId) {
                continue;
            }
            $position = (int) $row->position;
            if ($min === null || $position < $min) {
                $min = $position;
            }
        }

        return $min === null ? '' : sprintf('%08d', $min);
    }

    /**
     * @param  list<string>  $stored
     */
    private function userKey(array $stored): string
    {
        $best = null;
        $key = '';
        foreach ($stored as $value) {
            if (preg_match('/^[0-9]+$/', $value) !== 1) {
                continue;
            }
            $user = User::query()->find((int) $value);
            if (! $user instanceof User) {
                continue;
            }
            $candidate = mb_strtolower((string) $user->firstname)."\n".mb_strtolower((string) $user->lastname);
            if ($best === null || $candidate < $best) {
                $best = $candidate;
                $key = $candidate;
            }
        }

        return $key;
    }

    /**
     * @param  list<string>  $stored
     */
    private function versionKey(array $stored): string
    {
        $min = null;
        foreach ($stored as $value) {
            if (preg_match('/^[0-9]+$/', $value) !== 1) {
                continue;
            }
            $version = Version::query()->find((int) $value);
            if (! $version instanceof Version) {
                continue;
            }
            $name = mb_strtolower((string) $version->name);
            if ($min === null || $name < $min) {
                $min = $name;
            }
        }

        return $min ?? '';
    }

    private function numeric(string $value, bool $integer): ?string
    {
        $pattern = $integer
            ? '/^[+-]?[0-9]+$/'
            : '/^[+-]?([0-9]+(\.[0-9]+)?|\.[0-9]+)$/';
        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        return $value;
    }
}
