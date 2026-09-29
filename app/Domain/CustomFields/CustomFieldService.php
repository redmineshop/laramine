<?php

namespace App\Domain\CustomFields;

use App\Domain\DomainException;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates custom field definitions and their scope links.
 */
final class CustomFieldService
{
    public function __construct(private readonly FieldFormatRegistry $formats) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function save(array $attributes): CustomField
    {
        return DB::transaction(function () use ($attributes): CustomField {
            $existing = $this->existing($attributes);
            $type = $this->type($attributes, $existing);
            $formatKey = $this->formatKey($attributes, $existing);
            $format = $this->formats->get($formatKey);
            $name = $this->name($attributes, $existing);
            $this->assertUniqueName($type, $name, $existing?->id);

            if ($existing !== null && $existing->field_format !== $formatKey) {
                $used = CustomValue::query()->where('custom_field_id', $existing->id)->exists();
                if ($used) {
                    throw new DomainException('Field format cannot change while values exist.');
                }
            }

            $multiple = $this->boolAttribute($attributes, 'multiple', $this->storedBool($existing?->multiple));
            if ($multiple && ! $format->supportsMultiple()) {
                throw new DomainException('This format does not support multiple values.');
            }

            $searchable = $this->boolAttribute($attributes, 'searchable', $this->storedBool($existing?->searchable));
            if ($searchable && ! $format->supportsSearchable()) {
                throw new DomainException('This format does not support searchable.');
            }

            $field = $existing ?? new CustomField;
            $field->type = $type;
            $field->name = $name;
            $field->field_format = $formatKey;
            $field->multiple = $multiple;
            $field->searchable = $searchable;
            $field->is_required = $this->boolAttribute($attributes, 'is_required', $existing === null ? false : $existing->is_required);
            $field->is_filter = $this->boolAttribute($attributes, 'is_filter', $existing === null ? false : $existing->is_filter);
            $field->editable = $this->boolAttribute($attributes, 'editable', $this->editableDefault($existing));
            $field->visible = $this->boolAttribute($attributes, 'visible', $existing === null ? true : $existing->visible);
            $field->description = $this->nullableText($attributes, 'description', $existing?->description);
            $field->default_value = $this->nullableText($attributes, 'default_value', $existing?->default_value);
            $field->regexp = $this->pattern($attributes, $existing, $format);
            $field->min_length = $this->length($attributes, 'min_length', $existing?->min_length, $format);
            $field->max_length = $this->length($attributes, 'max_length', $existing?->max_length, $format);
            $field->setPossibleValueList($this->possibleValues($attributes, $existing));
            $field->setFormatStoreData($this->formatStore($attributes, $existing));
            $field->is_for_all = $this->forAll($attributes, $existing, $type);

            if (array_key_exists('position', $attributes) && $attributes['position'] !== null) {
                if (! is_numeric($attributes['position']) || (int) $attributes['position'] < 0) {
                    throw new DomainException('Position must be a non-negative integer.');
                }
                $field->position = (int) $attributes['position'];
            } elseif ($field->position === null) {
                $max = CustomField::query()->where('type', $type)->max('position');
                $field->position = is_numeric($max) ? ((int) $max + 1) : 1;
            }

            $definitionErrors = $format->validateDefinition($field);
            if ($definitionErrors !== []) {
                throw new DomainException($definitionErrors[0]);
            }

            $field->save();
            $this->syncScopes($field, $attributes, $existing === null);

            return $field->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function existing(array $attributes): ?CustomField
    {
        if (! array_key_exists('id', $attributes) || $attributes['id'] === null) {
            return null;
        }
        if (! is_numeric($attributes['id'])) {
            throw new DomainException('Custom field does not exist.');
        }
        $field = CustomField::query()->find((int) $attributes['id']);
        if (! $field instanceof CustomField) {
            throw new DomainException('Custom field does not exist.');
        }

        return $field;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function type(array $attributes, ?CustomField $existing): string
    {
        if ($existing !== null && ! array_key_exists('type', $attributes)) {
            return (string) $existing->type;
        }

        $type = $attributes['type'] ?? null;
        if (! is_string($type) || ! array_key_exists($type, CustomFieldTypes::SUPPORTED)) {
            throw new DomainException('Custom field type is not supported.');
        }
        if ($existing !== null && $type !== $existing->type) {
            throw new DomainException('Custom field type cannot change.');
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function formatKey(array $attributes, ?CustomField $existing): string
    {
        if ($existing !== null && ! array_key_exists('field_format', $attributes)) {
            return (string) $existing->field_format;
        }

        $format = $attributes['field_format'] ?? null;
        if (! is_string($format) || FieldFormatKey::tryFrom($format) === null) {
            throw new DomainException('Unknown custom field format.');
        }

        return $format;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function name(array $attributes, ?CustomField $existing): string
    {
        if ($existing !== null && ! array_key_exists('name', $attributes)) {
            return (string) $existing->name;
        }

        $name = $attributes['name'] ?? null;
        if (! is_string($name)) {
            throw new DomainException('Custom field name is required.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Custom field name is required.');
        }
        if (mb_strlen($name) > 30) {
            throw new DomainException('Custom field name must be 30 characters or fewer.');
        }

        return $name;
    }

    private function assertUniqueName(string $type, string $name, ?int $ignoreId): void
    {
        $query = CustomField::query()->where('type', $type)->where('name', $name);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw new DomainException('Custom field name is already used for this type.');
        }
    }

    private function storedBool(?bool $value): bool
    {
        return $value ?? false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function boolAttribute(array $attributes, string $key, bool $fallback): bool
    {
        if (! array_key_exists($key, $attributes) || $attributes[$key] === null) {
            return $fallback;
        }

        return (bool) $attributes[$key];
    }

    private function editableDefault(?CustomField $existing): bool
    {
        if ($existing === null) {
            return true;
        }

        $stored = $existing->getAttributes();
        if (! array_key_exists('editable', $stored)) {
            return true;
        }
        $raw = $stored['editable'];

        return $raw === null || $raw === true || $raw === 1 || $raw === '1';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nullableText(array $attributes, string $key, mixed $fallback): ?string
    {
        if (! array_key_exists($key, $attributes)) {
            return is_string($fallback) && $fallback !== '' ? $fallback : null;
        }
        $value = $attributes[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new DomainException($key.' must be a string.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pattern(array $attributes, ?CustomField $existing, FieldFormat $format): ?string
    {
        $value = array_key_exists('regexp', $attributes)
            ? $attributes['regexp']
            : $existing?->regexp;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw new DomainException('Pattern must be a string.');
        }
        if (! $this->allowsTextConstraints($format)) {
            throw new DomainException('This format does not support a pattern.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function length(array $attributes, string $key, mixed $fallback, FieldFormat $format): ?int
    {
        if (! array_key_exists($key, $attributes)) {
            return is_numeric($fallback) ? (int) $fallback : null;
        }
        $value = $attributes[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new DomainException($key.' must be an integer.');
        }
        if (! $this->allowsTextConstraints($format)) {
            throw new DomainException('This format does not support length bounds.');
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    private function possibleValues(array $attributes, ?CustomField $existing): ?array
    {
        if (! array_key_exists('possible_values', $attributes)) {
            if ($existing === null) {
                return null;
            }

            return $existing->possibleValueList();
        }
        $value = $attributes['possible_values'];
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException('possible_values must be a list of strings.');
        }
        $list = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new DomainException('possible_values must be a list of strings.');
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function formatStore(array $attributes, ?CustomField $existing): ?array
    {
        if (! array_key_exists('format_store', $attributes)) {
            if ($existing === null) {
                return null;
            }

            return $existing->formatStoreData();
        }
        $value = $attributes['format_store'];
        if ($value === null || $value === []) {
            return null;
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new DomainException('format_store must be an object.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function forAll(array $attributes, ?CustomField $existing, string $type): bool
    {
        $current = $existing === null ? false : $existing->is_for_all;
        $value = $this->boolAttribute($attributes, 'is_for_all', $current);
        if ($type !== 'IssueCustomField' && $value) {
            throw new DomainException('is_for_all applies only to issue custom fields.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncScopes(CustomField $field, array $attributes, bool $creating): void
    {
        $issue = $field->type === 'IssueCustomField';

        if (array_key_exists('tracker_ids', $attributes) || ($creating && $issue)) {
            $ids = $this->idList($attributes['tracker_ids'] ?? [], 'Tracker');
            if (! $issue && $ids !== []) {
                throw new DomainException('Trackers apply only to issue custom fields.');
            }
            if ($issue && $ids === []) {
                throw new DomainException('Issue custom fields need at least one tracker.');
            }
            $this->assertCount(Tracker::query()->whereIn('id', $ids)->count(), count($ids), 'Tracker');
            $field->trackers()->sync($ids);
        }

        if (array_key_exists('project_ids', $attributes) || ($creating && $issue && ! $field->is_for_all)) {
            $ids = $this->idList($attributes['project_ids'] ?? [], 'Project');
            if (! $issue && $ids !== []) {
                throw new DomainException('Projects apply only to issue custom fields.');
            }
            if ($issue && ! $field->is_for_all && $ids === []) {
                throw new DomainException('Issue custom fields need is_for_all or at least one project.');
            }
            $this->assertCount(Project::query()->whereIn('id', $ids)->count(), count($ids), 'Project');
            $field->projects()->sync($ids);
        }

        if (array_key_exists('role_ids', $attributes)) {
            $ids = $this->idList($attributes['role_ids'], 'Role');
            $this->assertCount(Role::query()->whereIn('id', $ids)->count(), count($ids), 'Role');
            $field->roles()->sync($ids);
        }

        if ($field->type === 'IssueCustomField') {
            if ($field->trackers()->count() === 0) {
                throw new DomainException('Issue custom fields need at least one tracker.');
            }
            if (! $field->is_for_all && $field->projects()->count() === 0) {
                throw new DomainException('Issue custom fields need is_for_all or at least one project.');
            }
        }
    }

    /**
     * @return list<int>
     */
    private function idList(mixed $raw, string $label): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw)) {
            throw new DomainException($label.' ids must be a list.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (! is_numeric($id) || (int) $id <= 0) {
                throw new DomainException($label.' id is invalid.');
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    private function assertCount(int $found, int $expected, string $label): void
    {
        if ($expected === 0) {
            return;
        }
        if ($found !== $expected) {
            throw new DomainException($label.' does not exist.');
        }
    }

    private function allowsTextConstraints(FieldFormat $format): bool
    {
        return in_array($format->key(), ['string', 'text', 'link'], true);
    }
}
