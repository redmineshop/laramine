<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

/**
 * Integer from 0 to 100. `format_store.ratio_interval` is an optional step.
 *
 * The step is a positive integer that divides 100, so 0 and 100 stay on the scale.
 * The format is not totalable, so IssueQuery rejects it in `totalable_names`.
 */
final class ProgressbarFormat extends AbstractFormat
{
    public function key(): string
    {
        return 'progressbar';
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

    public function validateDefinition(CustomField $field): array
    {
        if ($this->ratioInterval($field) === false) {
            return ['ratio_interval must be a positive integer that divides 100.'];
        }

        return parent::validateDefinition($field);
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        unset($customized);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $multiple = $this->rejectMultiple($raw);
        if ($multiple !== []) {
            return $multiple;
        }

        $interval = $this->ratioInterval($field);
        if ($interval === false) {
            return ['ratio_interval must be a positive integer that divides 100.'];
        }

        $canonical = $this->canonical($this->unwrap($raw));
        if ($canonical === null) {
            return ['Value must be an integer from 0 to 100.'];
        }

        $value = (int) $canonical;
        if ($value < 0 || $value > 100) {
            return ['Value must be an integer from 0 to 100.'];
        }

        if ($interval !== null && $interval > 0 && $value % $interval !== 0) {
            return ['Value is not a multiple of the ratio interval.'];
        }

        return [];
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '' || preg_match('/^[+-]?\d+$/', $stored) !== 1) {
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

    /**
     * Null means any integer from 0 to 100. False means the store value is invalid.
     */
    private function ratioInterval(CustomField $field): int|false|null
    {
        $store = $field->formatStoreData();
        if (! is_array($store) || ! array_key_exists('ratio_interval', $store)) {
            return null;
        }

        $raw = $store['ratio_interval'];
        if ($raw === null || $raw === '') {
            return null;
        }

        $interval = $this->positiveInt($raw);
        if ($interval === null || $interval > 100 || 100 % $interval !== 0) {
            return false;
        }

        return $interval;
    }

    private function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d*$/', trim($raw)) === 1) {
            $text = ltrim(trim($raw), '0');
            $value = (int) $text;
            if ((string) $value !== $text) {
                return null;
            }

            return $value;
        }

        return null;
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
