<?php

namespace App\Domain\TimeEntries;

use App\Models\Enumeration;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Chooses the activity stored on a new time entry when the caller omits one.
 *
 * One available activity is used immediately. Otherwise the roles on the
 * user's membership for the project are walked from lowest builtin, then
 * lowest position, then lowest id. A role copied from a group is included.
 * The first role default that matches an available activity, by its own id
 * or by a project activity whose parent is that id, wins. The project copy
 * of the marked default is next, then the marked default itself. A user
 * with no membership row skips the role default.
 */
final class TimeEntryActivityDefaults
{
    public function idFor(?User $user, Project $project): ?int
    {
        $available = $this->available($project);
        if ($available === []) {
            return null;
        }
        if (count($available) === 1) {
            return (int) $available[0]->id;
        }

        if ($user instanceof User) {
            $member = Member::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->first();
            if ($member instanceof Member) {
                $matched = $this->match($available, $this->roleDefaultIds($member));
                if ($matched !== null) {
                    return $matched;
                }
            }

            $projectDefault = $this->projectDefault($available);
            if ($projectDefault instanceof Enumeration) {
                $matched = $this->match($available, [(int) $projectDefault->id]);
                if ($matched !== null) {
                    return $matched;
                }
            }
        }

        $marked = $this->markedDefault();
        if ($marked instanceof Enumeration) {
            return $this->match($available, [(int) $marked->id]);
        }

        return null;
    }

    /**
     * Active system activities, plus this project's activities, without a
     * system row that this project has replaced.
     *
     * @return list<Enumeration>
     */
    private function available(Project $project): array
    {
        $overridden = Enumeration::query()
            ->where('type', 'TimeEntryActivity')
            ->where('project_id', $project->id)
            ->whereNotNull('parent_id')
            ->pluck('parent_id')
            ->all();

        $query = Enumeration::query()
            ->where('type', 'TimeEntryActivity')
            ->where('active', true)
            ->where(function ($inner) use ($project): void {
                $inner->whereNull('project_id')->orWhere('project_id', $project->id);
            })
            ->orderBy('position')
            ->orderBy('id');
        if ($overridden !== []) {
            $query->whereNotIn('id', $overridden);
        }

        $rows = [];
        foreach ($query->get() as $row) {
            if ($row instanceof Enumeration) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<Enumeration>  $available
     * @param  list<int>  $ids
     */
    private function match(array $available, array $ids): ?int
    {
        foreach ($ids as $id) {
            foreach ($available as $activity) {
                $parent = $activity->parent_id;
                if ((int) $activity->id === $id || ($parent !== null && (int) $parent === $id)) {
                    return (int) $activity->id;
                }
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function roleDefaultIds(Member $member): array
    {
        $roleIds = [];
        foreach (MemberRole::query()->where('member_id', $member->id)->pluck('role_id') as $roleId) {
            if (is_numeric($roleId)) {
                $roleIds[] = (int) $roleId;
            }
        }
        if ($roleIds === []) {
            return [];
        }

        $roles = [];
        foreach (Role::query()->whereIn('id', $roleIds)->whereNotNull('default_time_entry_activity_id')->get() as $role) {
            if ($role instanceof Role) {
                $roles[] = $role;
            }
        }
        usort($roles, function (Role $left, Role $right): int {
            $builtin = ((int) $left->builtin) <=> ((int) $right->builtin);
            if ($builtin !== 0) {
                return $builtin;
            }
            $position = ((int) $left->position) <=> ((int) $right->position);
            if ($position !== 0) {
                return $position;
            }

            return ((int) $left->id) <=> ((int) $right->id);
        });

        $ids = [];
        foreach ($roles as $role) {
            $ids[] = (int) $role->default_time_entry_activity_id;
        }

        return $ids;
    }

    /**
     * @param  list<Enumeration>  $available
     */
    private function projectDefault(array $available): ?Enumeration
    {
        $marked = $this->markedDefault();
        if (! $marked instanceof Enumeration || $available === []) {
            return $marked;
        }
        foreach ($available as $activity) {
            if ((int) $activity->id === (int) $marked->id) {
                return $marked;
            }
        }
        foreach ($available as $activity) {
            if ($activity->parent_id !== null && (int) $activity->parent_id === (int) $marked->id) {
                return $activity;
            }
        }

        return null;
    }

    private function markedDefault(): ?Enumeration
    {
        $row = Enumeration::query()
            ->where('type', 'TimeEntryActivity')
            ->where('is_default', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return $row instanceof Enumeration ? $row : null;
    }
}
