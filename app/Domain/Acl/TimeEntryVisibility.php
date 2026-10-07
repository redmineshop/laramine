<?php

namespace App\Domain\Acl;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Time-entry list scope for `roles.time_entries_visibility`.
 *
 * Only roles that grant `view_time_entries` count. `all` shows every row.
 * `own` keeps rows whose `user_id` is the actor. Several roles use the most
 * open value. Any other stored value contributes nothing. Active admins are
 * not filtered. Issue query `spent_hours` and the `spent_time` filter use
 * {@see self::mode}.
 */
final class TimeEntryVisibility
{
    public const ALL = 'all';

    public const OWN = 'own';

    public const NONE = 'none';

    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @param  Builder<TimeEntry>  $query
     * @return Builder<TimeEntry>
     */
    public function apply(Builder $query, User $actor, Project $project): Builder
    {
        $mode = $this->mode($actor, $project);
        if ($mode === self::ALL) {
            return $query;
        }
        if ($mode === self::OWN) {
            return $query->where('user_id', (int) $actor->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function mode(User $actor, Project $project): string
    {
        if ($actor->admin && $actor->isActive()) {
            return self::ALL;
        }

        $seesAll = false;
        $seesOwn = false;
        foreach ($this->permissions->rolesFor($actor, $project) as $role) {
            if (! $role->grants('view_time_entries')) {
                continue;
            }
            $value = (string) $role->time_entries_visibility;
            if ($value === self::ALL) {
                $seesAll = true;
            } elseif ($value === self::OWN) {
                $seesOwn = true;
            }
        }

        if ($seesAll) {
            return self::ALL;
        }
        if ($seesOwn) {
            return self::OWN;
        }

        return self::NONE;
    }
}
