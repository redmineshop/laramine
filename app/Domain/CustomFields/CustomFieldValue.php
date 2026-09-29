<?php

namespace App\Domain\CustomFields;

/**
 * One custom field as returned on a record: cast value plus stored strings.
 */
final class CustomFieldValue
{
    /**
     * @param  list<string>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $fieldFormat,
        public readonly mixed $value,
        public readonly array $raw,
    ) {}

    /**
     * @return array{id: int, name: string, field_format: string, value: mixed, raw: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'field_format' => $this->fieldFormat,
            'value' => $this->value,
            'raw' => $this->raw,
        ];
    }
}
