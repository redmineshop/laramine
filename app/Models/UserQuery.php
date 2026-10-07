<?php

namespace App\Models;

use App\Domain\Queries\QueryType;
use Illuminate\Database\Eloquent\Builder;

/**
 * STI row for a user directory query. `UserQueryRunner` executes it.
 */
class UserQuery extends Query
{
    protected static function booted(): void
    {
        static::addGlobalScope('sti_type', function (Builder $builder): void {
            $builder->where('queries.type', QueryType::USER);
        });

        static::creating(function (UserQuery $query): void {
            $query->type = QueryType::USER;
        });
    }
}
