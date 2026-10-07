<?php

namespace App\Models;

use App\Domain\Acl\JsonObjectCast;
use App\Domain\Acl\PermissionList;
use App\Domain\Acl\PermissionListCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `roles` row.
 *
 * `permissions` is a JSON array of permission name strings. The cast also
 * reads a Redmine YAML symbol list. See docs/domain.md.
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
            'permissions' => PermissionListCast::class,
            'settings' => JsonObjectCast::class,
        ];
    }

    public function grants(string $permission): bool
    {
        $stored = $this->getAttributes()['permissions'] ?? null;

        return in_array($permission, PermissionList::decode($stored), true);
    }

    /**
     * Field rules and transitions use only roles that can add or edit issues.
     * A view-only role must not loosen a missing row or add a transition.
     */
    public function considersWorkflow(): bool
    {
        return $this->grants('add_issues')
            || $this->grants('edit_issues')
            || $this->grants('edit_own_issues');
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
