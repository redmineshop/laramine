<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `roles_managed_roles` join (no surrogate primary key).
 */
class RoleManagedRole extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'roles_managed_roles';

    protected $primaryKey = 'role_id';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function managedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'managed_role_id');
    }
}
