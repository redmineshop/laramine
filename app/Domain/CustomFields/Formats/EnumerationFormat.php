<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores `custom_field_enumerations.id` strings. Only active rows of this field count.
 * Multiple values are one row each. Option rows are not created here.
 */
final class EnumerationFormat extends AbstractFormat
{
    public function key(): string
    {
        return 'enumeration';
    }

    public function supportsMultiple(): bool
    {
        return true;
    }

    public function supportsSearchable(): bool
    {
        return false;
    }

    public function queryFilterType(): string
    {
        return 'list_optional';
    }

    public function validateDefinition(CustomField $field): array
    {
        $default = FieldValues::defaultString($field);
        if ($default === null) {
            return [];
        }

        if ($this->canonicalId($default) === null) {
            return ['Default value must be an enumeration id.'];
        }

        if ($field->id === null) {
            return [];
        }

        return $this->validate($field, $default, null);
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        unset($customized);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->parseIds($raw, (bool) $field->multiple);
        if ($parsed['error'] !== null) {
            return [$parsed['error']];
        }

        $errors = [];
        $seen = [];
        foreach ($parsed['ids'] as $id) {
            if (isset($seen[$id])) {
                $errors[] = 'Value is repeated.';
                break;
            }
            $seen[$id] = true;
            $row = CustomFieldEnumeration::query()->find($id);
            if (! $row instanceof CustomFieldEnumeration || (int) $row->custom_field_id !== (int) $field->id) {
                $errors[] = 'Enumeration does not exist.';
                break;
            }
            if (! $row->active) {
                $errors[] = 'Enumeration is not active.';
                break;
            }
        }

        return $errors;
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '' || ! ctype_digit($stored)) {
            return null;
        }

        return (int) $stored;
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->parseIds($raw, (bool) $field->multiple);
        $rows = [];
        foreach ($parsed['ids'] as $id) {
            $rows[] = (string) $id;
        }

        return $rows;
    }

    /**
     * @return array{ids: list<int>, error: ?string}
     */
    private function parseIds(mixed $raw, bool $multiple): array
    {
        $source = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($source as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $id = $this->canonicalId($item);
            if ($id === null) {
                return ['ids' => [], 'error' => 'Value must be an enumeration id.'];
            }
            $ids[] = $id;
        }

        if (! $multiple && count($ids) > 1) {
            return ['ids' => [], 'error' => 'Multiple values are not supported for this format.'];
        }

        return ['ids' => $ids, 'error' => null];
    }

    private function canonicalId(mixed $raw): ?int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d*$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }
}
