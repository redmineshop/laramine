<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Redmine 7.0.1 `watchers` row (`watchable_type` / `watchable_id`).
 */
class Watcher extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function watchable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
