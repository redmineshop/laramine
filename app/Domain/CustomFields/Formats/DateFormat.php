<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

final class DateFormat extends AbstractFormat
{
    public function key(): string
    {
        return 'date';
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
        return 'date';
    }

    public function defaultRaw(CustomField $field): mixed
    {
        $default = FieldValues::defaultString($field);
        if ($default === null) {
            return null;
        }

        if ($this->mode($field) === 'date_offset') {
            $days = (int) trim($default);

            return now()->startOfDay()->addDays($days)->toDateString();
        }

        return $default;
    }

    public function validateDefinition(CustomField $field): array
    {
        $mode = $this->mode($field);
        $store = $field->formatStoreData();
        if (is_array($store) && array_key_exists('default_value_mode', $store) && ! in_array($mode, ['fixed_date', 'date_offset'], true)) {
            return ['default_value_mode must be fixed_date or date_offset.'];
        }

        $default = FieldValues::defaultString($field);
        if ($default === null) {
            return [];
        }

        if ($mode === 'date_offset') {
            if (preg_match('/^[+-]?\d+$/', trim($default)) !== 1) {
                return ['Date offset default must be an integer number of days.'];
            }

            return [];
        }

        if ($this->canonical($default) === null) {
            return ['Default value is not a valid date (YYYY-MM-DD).'];
        }

        return [];
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
            return ['Value is not a valid date (YYYY-MM-DD).'];
        }

        return [];
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

    private function mode(CustomField $field): string
    {
        $store = $field->formatStoreData();
        if (! is_array($store)) {
            return 'fixed_date';
        }

        $mode = $store['default_value_mode'] ?? 'fixed_date';

        return is_string($mode) ? $mode : 'fixed_date';
    }

    private function canonical(mixed $raw): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        if (! is_string($raw)) {
            return null;
        }

        $text = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false) {
            return null;
        }
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed->format('Y-m-d') === $text ? $text : null;
    }
}
