<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `groups_users` join (no surrogate primary key).
 */
class GroupUser extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'groups_users';

    protected $primaryKey = 'group_id';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(User::class, 'group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
