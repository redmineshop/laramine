<?php

namespace App\Domain\Queries;

use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * JSON codec for `queries.filters`, `column_names`, `sort_criteria`, and `options`.
 *
 * Writes are JSON. Reads also accept a Redmine YAML dump: symbol keys (`:operator`)
 * and symbol list items (`:tracker`) lose the leading colon. An empty YAML document
 * such as `--- {}` (which the YAML parser returns as null) is an empty payload.
 */
final class QueryPayload
{
    /**
     * @return array<string, array{operator: string, values: list<string>}>
     */
    public static function filters(mixed $stored): array
    {
        if (is_array($stored)) {
            return self::normalizeFilters($stored);
        }

        $decoded = self::decode($stored);
        if ($decoded === null) {
            return [];
        }

        if (! is_array($decoded)) {
            throw new QueryValidationException('Saved query filters could not be read.');
        }

        return self::normalizeFilters($decoded);
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $map
     */
    public static function encodeFilters(array $map): string
    {
        if ($map === []) {
            return '{}';
        }

        return self::encode($map);
    }

    /**
     * @return list<string>|null
     */
    public static function columnNames(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = is_array($stored) ? $stored : self::decode($stored);
        if ($decoded === null) {
            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new QueryValidationException('Saved query columns could not be read.');
        }

        $names = [];
        foreach ($decoded as $item) {
            $name = self::scalarString($item);
            if ($name === null || $name === '') {
                throw new QueryValidationException('Saved query columns could not be read.');
            }
            if (preg_match('/^[A-Za-z0-9_.]+$/', $name) !== 1) {
                throw new QueryValidationException('Column name is invalid: '.$name.'.');
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  list<string>|null  $names
     */
    public static function encodeColumnNames(?array $names): ?string
    {
        if ($names === null) {
            return null;
        }

        return self::encode($names);
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    public static function sort(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = is_array($stored) ? $stored : self::decode($stored);
        if ($decoded === null) {
            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new QueryValidationException('Saved query sort could not be read.');
        }

        $pairs = [];
        foreach ($decoded as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) < 2) {
                throw new QueryValidationException('Saved query sort could not be read.');
            }
            $column = self::scalarString($pair[0]);
            $direction = strtolower(self::scalarString($pair[1]) ?? '');
            if ($column === null || $column === '' || ($direction !== 'asc' && $direction !== 'desc')) {
                throw new QueryValidationException('Saved query sort could not be read.');
            }
            $pairs[] = [$column, $direction];
        }

        return $pairs;
    }

    /**
     * @param  list<array{0: string, 1: string}>|null  $sort
     */
    public static function encodeSort(?array $sort): ?string
    {
        if ($sort === null) {
            return null;
        }

        return self::encode($sort);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function options(mixed $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = is_array($stored) ? $stored : self::decode($stored);
        if ($decoded === null) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new QueryValidationException('Saved query options could not be read.');
        }

        $options = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new QueryValidationException('Saved query options could not be read.');
            }
            $options[$key] = $value;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    public static function encodeOptions(?array $options): ?string
    {
        if ($options === null) {
            return null;
        }

        if ($options === []) {
            return '{}';
        }

        return self::encode($options);
    }

    /**
     * @param  array<mixed>  $decoded
     * @return array<string, array{operator: string, values: list<string>}>
     */
    private static function normalizeFilters(array $decoded): array
    {
        if ($decoded === []) {
            return [];
        }

        if (array_is_list($decoded)) {
            return self::mapFromList($decoded);
        }

        $map = [];
        foreach ($decoded as $field => $clause) {
            if (! is_string($field) || $field === '') {
                throw new QueryValidationException('Saved query filters could not be read.');
            }
            if (isset($map[$field])) {
                throw new QueryValidationException('Filter field is repeated: '.$field.'.');
            }
            $map[$field] = self::clause($field, $clause);
        }

        return $map;
    }

    /**
     * @param  list<mixed>  $decoded
     * @return array<string, array{operator: string, values: list<string>}>
     */
    private static function mapFromList(array $decoded): array
    {
        $map = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                throw new QueryValidationException('Saved query filters could not be read.');
            }
            $field = $item['field'] ?? null;
            if (! is_string($field) || $field === '') {
                throw new QueryValidationException('Saved query filters could not be read.');
            }
            if (isset($map[$field])) {
                throw new QueryValidationException('Filter field is repeated: '.$field.'.');
            }
            $operator = $item['op'] ?? $item['operator'] ?? null;
            $values = $item['values'] ?? [];
            $map[$field] = self::clause($field, [
                'operator' => $operator,
                'values' => $values,
            ]);
        }

        return $map;
    }

    /**
     * @return array{operator: string, values: list<string>}
     */
    private static function clause(string $field, mixed $clause): array
    {
        if (! is_array($clause)) {
            throw new QueryValidationException('Saved query filters could not be read.');
        }

        $operator = $clause['operator'] ?? $clause['op'] ?? null;
        if (! is_string($operator) || $operator === '') {
            throw new QueryValidationException('Filter '.$field.' needs an operator.');
        }

        $rawValues = $clause['values'] ?? [];
        if (! is_array($rawValues) || ! array_is_list($rawValues)) {
            throw new QueryValidationException('Filter '.$field.' values must be a list.');
        }

        $values = [];
        foreach ($rawValues as $value) {
            $text = self::scalarString($value);
            if ($text === null) {
                throw new QueryValidationException('Filter '.$field.' has a value that is not a scalar.');
            }
            $values[] = $text;
        }

        return [
            'operator' => $operator,
            'values' => $values,
        ];
    }

    private static function scalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            return str_starts_with($value, ':') ? substr($value, 1) : $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            if (floor($value) === $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return null;
    }

    private static function decode(mixed $stored): mixed
    {
        if ($stored === null) {
            return null;
        }

        if (! is_string($stored)) {
            throw new QueryValidationException('Saved query payload could not be read.');
        }

        $trimmed = ltrim($stored);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^---\s*\{\s*\}\s*$/', $trimmed) === 1) {
            return [];
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            try {
                return json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new QueryValidationException('Saved query JSON could not be read.');
            }
        }

        try {
            $parsed = Yaml::parse($stored);
        } catch (ParseException) {
            throw new QueryValidationException('Saved query YAML could not be read.');
        }

        if ($parsed === null) {
            return null;
        }

        return self::desymbolize($parsed);
    }

    private static function desymbolize(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_starts_with($value, ':') ? substr($value, 1) : $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) && str_starts_with($key, ':') ? substr($key, 1) : $key;
            $out[$normalizedKey] = self::desymbolize($item);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
