<?php

namespace App\Models;

use App\Domain\Queries\QueryType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stub STI row. Project admin query filters are not executed.
 */
class ProjectAdminQuery extends Query
{
    protected static function booted(): void
    {
        static::addGlobalScope('sti_type', function (Builder $builder): void {
            $builder->where('queries.type', QueryType::PROJECT_ADMIN);
        });

        static::creating(function (ProjectAdminQuery $query): void {
            $query->type = QueryType::PROJECT_ADMIN;
        });
    }
}
