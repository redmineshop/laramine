<?php

namespace App\Domain\Acl;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<list<string>, mixed>
 */
class PermissionListCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return PermissionList::decode($value);
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
            throw new InvalidArgumentException('Role permissions must be a list of strings or a stored codec string.');
        }

        $names = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException('Each permission name must be a non-empty string.');
            }
            $names[] = $item;
        }

        return PermissionList::encode($names);
    }
}
