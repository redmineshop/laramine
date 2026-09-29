<?php

namespace App\Domain\Acl;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * JSON object/array cast that leaves non-JSON text unread.
 *
 * ETL may still store a Redmine serialized blob in the same text column.
 * Those values decode as null until a migrator rewrites them as JSON.
 *
 * @implements CastsAttributes<array<string, mixed>|null, mixed>
 */
class JsonObjectCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || ltrim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return null;
        }

        $object = [];
        foreach ($decoded as $itemKey => $item) {
            if (is_string($itemKey)) {
                $object[$itemKey] = $item;
            }
        }

        if ($object !== []) {
            return $object;
        }

        if (array_is_list($decoded)) {
            return ['_list' => $decoded];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException('JSON columns accept an array or a raw string.');
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $encoded;
    }
}
