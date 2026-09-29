<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

/**
 * String and text share length and pattern checks. Multiple values are rejected.
 */
abstract class BoundedTextFormat extends AbstractFormat
{
    public function supportsMultiple(): bool
    {
        return false;
    }

    public function supportsSearchable(): bool
    {
        return true;
    }

    public function validateDefinition(CustomField $field): array
    {
        $errors = [];
        $pattern = $field->regexp;
        if (is_string($pattern) && $pattern !== '' && FieldValues::compilePattern($pattern) === null) {
            $errors[] = 'Pattern is not a valid regular expression.';
        }

        $min = $field->min_length;
        $max = $field->max_length;
        if ($min !== null && $min < 0) {
            $errors[] = 'Minimum length cannot be negative.';
        }
        if ($max !== null && $max < 0) {
            $errors[] = 'Maximum length cannot be negative.';
        }
        if ($min !== null && $max !== null && $min > $max) {
            $errors[] = 'Minimum length cannot exceed maximum length.';
        }
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

        $multiple = $this->rejectMultiple($raw);
        if ($multiple !== []) {
            return $multiple;
        }

        $raw = $this->unwrap($raw);
        if (! is_string($raw)) {
            return ['Value must be a string.'];
        }

        $errors = [];
        $length = mb_strlen($raw);
        $min = $field->min_length;
        $max = $field->max_length;
        if ($min !== null && $length < $min) {
            $errors[] = 'Value is shorter than the minimum length.';
        }
        if ($max !== null && $length > $max) {
            $errors[] = 'Value is longer than the maximum length.';
        }

        $pattern = $field->regexp;
        if (is_string($pattern) && $pattern !== '') {
            $regex = FieldValues::compilePattern($pattern);
            if ($regex === null) {
                $errors[] = 'Custom field pattern is invalid.';
            } elseif (preg_match($regex, $raw) !== 1) {
                $errors[] = 'Value does not match the pattern.';
            }
        }

        return $errors;
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        unset($field);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $raw = $this->unwrap($raw);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return [$raw];
    }
}
