<?php

namespace App\Domain\CustomFields;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

/**
 * One custom field format: definition checks, value validation, and storage.
 */
interface FieldFormat
{
    public function key(): string;

    public function supportsMultiple(): bool;

    public function supportsSearchable(): bool;

    public function queryFilterType(): string;

    public function isImplemented(): bool;

    /**
     * @return list<string>
     */
    public function validateDefinition(CustomField $field): array;

    /**
     * @return list<string>
     */
    public function validate(CustomField $field, mixed $raw, ?Model $customized): array;

    public function cast(CustomField $field, ?string $stored): mixed;

    /**
     * Stored `custom_values.value` strings. An empty list deletes every row.
     *
     * @return list<string>
     */
    public function serialize(CustomField $field, mixed $raw): array;

    public function defaultRaw(CustomField $field): mixed;
}
