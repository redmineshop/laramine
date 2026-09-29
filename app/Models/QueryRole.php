<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `queries_roles` join (no surrogate primary key).
 */
class QueryRole extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'queries_roles';

    protected $primaryKey = 'query_id';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Query, $this>
     */
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
