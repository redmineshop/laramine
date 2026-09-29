<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldFormat;
use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;

abstract class AbstractFormat implements FieldFormat
{
    public function isImplemented(): bool
    {
        return true;
    }

    public function defaultRaw(CustomField $field): mixed
    {
        return FieldValues::defaultString($field);
    }

    public function validateDefinition(CustomField $field): array
    {
        $raw = $this->defaultRaw($field);
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        return $this->validate($field, $raw, null);
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return $stored;
    }

    /**
     * One non-blank entry of a single-value payload, or the original array when
     * more than one entry is present.
     */
    protected function unwrap(mixed $raw): mixed
    {
        if (! is_array($raw)) {
            return $raw;
        }

        $items = [];
        foreach ($raw as $item) {
            if ($item !== null && $item !== '') {
                $items[] = $item;
            }
        }

        if (count($items) === 1) {
            return $items[0];
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    protected function rejectMultiple(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $count = 0;
        foreach ($raw as $item) {
            if ($item !== null && $item !== '') {
                $count++;
            }
        }

        if ($count > 1) {
            return ['Multiple values are not supported for this format.'];
        }

        return [];
    }
}
