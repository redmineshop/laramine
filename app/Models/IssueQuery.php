<?php

namespace App\Models;

use App\Domain\Queries\QueryType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved issue query (`queries.type = IssueQuery`).
 */
class IssueQuery extends Query
{
    protected static function booted(): void
    {
        static::addGlobalScope('sti_type', function (Builder $builder): void {
            $builder->where('queries.type', QueryType::ISSUE);
        });

        static::creating(function (IssueQuery $query): void {
            $query->type = QueryType::ISSUE;
        });
    }
}
