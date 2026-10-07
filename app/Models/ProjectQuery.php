<?php

namespace App\Models;

use App\Domain\Queries\QueryType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved project list. `ProjectQueryRunner` executes the filters.
 */
class ProjectQuery extends Query
{
    protected static function booted(): void
    {
        static::addGlobalScope('sti_type', function (Builder $builder): void {
            $builder->where('queries.type', QueryType::PROJECT);
        });

        static::creating(function (ProjectQuery $query): void {
            $query->type = QueryType::PROJECT;
        });
    }
}
