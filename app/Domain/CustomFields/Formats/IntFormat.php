<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

final class IntFormat extends AbstractFormat
{
    public function key(): string
    {
        return 'int';
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
        return 'integer';
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
            return ['Value is not a valid integer.'];
        }

        return [];
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '') {
            return null;
        }

        return (int) $stored;
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
        if (is_bool($raw) || is_float($raw)) {
            return null;
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (! is_string($raw)) {
            return null;
        }

        $text = trim($raw);
        if (preg_match('/^[+-]?\d+$/', $text) !== 1) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        $digits = ltrim($text, '+-');
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '0';
        }

        $canonical = $negative ? '-'.$digits : $digits;
        $asInt = (int) $canonical;
        if ((string) $asInt !== $canonical) {
            return null;
        }

        return $canonical;
    }
}
