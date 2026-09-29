<?php

namespace App\Domain\CustomFields;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * JSON list of strings stored in a text column.
 *
 * Non-JSON text (a Redmine YAML dump, for example) decodes as null.
 *
 * @implements CastsAttributes<list<string>|null, mixed>
 */
class JsonListCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        unset($model, $key, $attributes);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $list = [];
        foreach ($decoded as $item) {
            if (! is_string($item)) {
                return null;
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        unset($model, $key, $attributes);

        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('possible_values must be a list of strings or null.');
        }

        $list = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('possible_values must be a list of strings or null.');
            }
            $list[] = $item;
        }

        return json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
