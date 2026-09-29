<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

final class ListFormat extends AbstractFormat
{
    public function key(): string
    {
        return 'list';
    }

    public function supportsMultiple(): bool
    {
        return true;
    }

    public function supportsSearchable(): bool
    {
        return true;
    }

    public function queryFilterType(): string
    {
        return 'list_optional';
    }

    public function validateDefinition(CustomField $field): array
    {
        $errors = $this->possibleValueErrors($field);
        if ($errors !== []) {
            return $errors;
        }

        return parent::validateDefinition($field);
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        unset($customized);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->items($raw, (bool) $field->multiple);
        if ($parsed['error'] !== null) {
            return [$parsed['error']];
        }
        $items = $parsed['items'];

        $allowed = $this->allowed($field);
        if ($allowed === []) {
            return ['List fields require possible_values.'];
        }

        $errors = [];
        $seen = [];
        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                $errors[] = 'Value is not in the list of possible values.';
                break;
            }
            if (isset($seen[$item])) {
                $errors[] = 'Value is repeated.';
                break;
            }
            $seen[$item] = true;
        }

        return $errors;
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '') {
            return null;
        }

        return $stored;
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->items($raw, (bool) $field->multiple);

        return $parsed['items'];
    }

    /**
     * @return list<string>
     */
    private function possibleValueErrors(CustomField $field): array
    {
        $values = $field->possibleValueList();
        if ($values === null || $values === []) {
            return ['List fields require possible_values.'];
        }

        $seen = [];
        foreach ($values as $value) {
            if ($value === '') {
                return ['Possible values must be non-empty strings.'];
            }
            if (isset($seen[$value])) {
                return ['Possible values must be unique.'];
            }
            $seen[$value] = true;
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function allowed(CustomField $field): array
    {
        $values = $field->possibleValueList();
        if ($values === null) {
            return [];
        }

        $allowed = [];
        foreach ($values as $value) {
            if ($value !== '') {
                $allowed[] = $value;
            }
        }

        return $allowed;
    }

    /**
     * @return array{items: list<string>, error: ?string}
     */
    private function items(mixed $raw, bool $multiple): array
    {
        $source = is_array($raw) ? $raw : [$raw];
        $items = [];
        foreach ($source as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            if (! is_string($item) && ! is_int($item) && ! is_float($item)) {
                return ['items' => [], 'error' => 'Value is not in the list of possible values.'];
            }
            $items[] = (string) $item;
        }

        if (! $multiple && count($items) > 1) {
            return ['items' => [], 'error' => 'Multiple values are not supported for this format.'];
        }

        return ['items' => $items, 'error' => null];
    }
}
