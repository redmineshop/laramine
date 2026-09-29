<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

/**
 * Recognized format whose value rules are not implemented yet.
 */
final class UnsupportedFormat extends AbstractFormat
{
    public function __construct(
        private readonly string $formatKey,
        private readonly string $filterType,
        private readonly bool $multiple,
        private readonly bool $searchable,
    ) {}

    public function key(): string
    {
        return $this->formatKey;
    }

    public function supportsMultiple(): bool
    {
        return $this->multiple;
    }

    public function supportsSearchable(): bool
    {
        return $this->searchable;
    }

    public function queryFilterType(): string
    {
        return $this->filterType;
    }

    public function isImplemented(): bool
    {
        return false;
    }

    public function defaultRaw(CustomField $field): mixed
    {
        unset($field);

        return null;
    }

    public function validateDefinition(CustomField $field): array
    {
        unset($field);

        return [];
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        unset($field, $customized);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        return ['The '.$this->formatKey.' format is not supported.'];
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        unset($field, $raw);

        return [];
    }
}
