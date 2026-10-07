<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

final class BoolFormat extends AbstractFormat
{
    public function key(): string
    {
        return 'bool';
    }

    public function supportsMultiple(): bool
    {
        return false;
    }

    public function supportsSearchable(): bool
    {
        return false;
    }

    public function queryFilterType(): string
    {
        return 'list_optional_with_history';
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        unset($field, $customized);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $multiple = $this->rejectMultiple($raw);
        if ($multiple !== []) {
            return $multiple;
        }

        if ($this->canonical($this->unwrap($raw)) === null) {
            return ['Value must be 1 or 0.'];
        }

        return [];
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '') {
            return null;
        }

        return $stored === '1';
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        unset($field);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $canonical = $this->canonical($this->unwrap($raw));
        if ($canonical === null) {
            return [];
        }

        return [$canonical];
    }

    private function canonical(mixed $raw): ?string
    {
        if ($raw === true || $raw === 1 || $raw === '1') {
            return '1';
        }

        if ($raw === false || $raw === 0 || $raw === '0') {
            return '0';
        }

        if (is_string($raw)) {
            $text = trim($raw);
            if ($text === '1' || $text === '0') {
                return $text;
            }
        }

        return null;
    }
}
