<?php

namespace App\Models;

use App\Domain\Queries\QueryType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stub STI row. Time entry query filters are not executed.
 */
class TimeEntryQuery extends Query
{
    protected static function booted(): void
    {
        static::addGlobalScope('sti_type', function (Builder $builder): void {
            $builder->where('queries.type', QueryType::TIME_ENTRY);
        });

        static::creating(function (TimeEntryQuery $query): void {
            $query->type = QueryType::TIME_ENTRY;
        });
    }
}
