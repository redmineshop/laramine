<?php

namespace App\Domain\CustomFields;

use App\Models\CustomField;

/**
 * Shared blank checks and pattern compilation for field formats.
 */
final class FieldValues
{
    public static function isBlank(mixed $raw): bool
    {
        if ($raw === null || $raw === '') {
            return true;
        }

        if (! is_array($raw)) {
            return false;
        }

        foreach ($raw as $item) {
            if ($item !== null && $item !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Compile a delimiter-free pattern. Null means the pattern is not valid PCRE.
     */
    public static function compilePattern(string $pattern): ?string
    {
        if ($pattern === '') {
            return null;
        }

        $escaped = str_replace('#', '\#', $pattern);
        $regex = '#'.$escaped.'#';
        $result = @preg_match($regex, '');
        if ($result === false) {
            return null;
        }

        return $regex;
    }

    public static function defaultString(CustomField $field): ?string
    {
        $default = $field->default_value;
        if (! is_string($default)) {
            return null;
        }

        if ($default === '') {
            return null;
        }

        return $default;
    }
}
