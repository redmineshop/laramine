<?php

namespace App\Domain\Acl;

/**
 * Codec for `roles.permissions`.
 *
 * Laramine writes a JSON array of permission name strings. A Redmine YAML
 * symbol list (`- :view_issues`) is accepted on read so an ETL load can be
 * interpreted before it is rewritten as JSON.
 */
final class PermissionList
{
    /**
     * @return list<string>
     */
    public static function decode(mixed $stored): array
    {
        if (! is_string($stored)) {
            return [];
        }

        $trimmed = ltrim($stored);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($stored, true);
            if (! is_array($decoded)) {
                return [];
            }

            return self::normalize($decoded);
        }

        preg_match_all('/:([a-z][a-z0-9_]*)/', $stored, $matches);

        return self::normalize($matches[1]);
    }

    /**
     * @param  list<string>  $names
     */
    public static function encode(array $names): string
    {
        $encoded = json_encode(self::normalize($names), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $encoded;
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private static function normalize(array $names): array
    {
        $unique = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $unique[$name] = $name;
        }

        return array_values($unique);
    }
}
