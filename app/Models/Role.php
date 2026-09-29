<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `roles` row.
 *
 * `permissions` is a serialized list and is not evaluated here.
 */
class Role extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'all_roles_managed' => 'boolean',
            'assignable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Enumeration, $this>
     */
    public function defaultTimeEntryActivity(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'default_time_entry_activity_id');
    }

    /**
     * @return HasMany<MemberRole, $this>
     */
    public function memberRoles(): HasMany
    {
        return $this->hasMany(MemberRole::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function managedRoles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'roles_managed_roles', 'role_id', 'managed_role_id');
    }
}
