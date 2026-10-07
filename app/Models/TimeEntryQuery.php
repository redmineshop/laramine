<?php

namespace App\Models;

use App\Domain\Queries\QueryType;
use App\Domain\TimeEntries\TimeEntryQueryRunner;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved time-entry query. {@see TimeEntryQueryRunner} executes it.
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
