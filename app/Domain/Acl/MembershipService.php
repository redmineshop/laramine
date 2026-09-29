<?php

namespace App\Domain\Acl;

use App\Domain\DomainException;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Membership writes, group expansion, and inherit_members propagation.
 *
 * Builtin roles are not stored on members. Callers enforce manage_members;
 * this service does not, so tests and ETL can assign roles directly.
 */
final class MembershipService
{
    public function assignRole(Project $project, User $principal, Role $role): MemberRole
    {
        if ((int) $role->builtin !== BuiltinRole::CUSTOM) {
            throw new DomainException('Builtin roles cannot be assigned through membership.');
        }

        return DB::transaction(function () use ($project, $principal, $role): MemberRole {
            $member = $this->findOrCreateMember($project, $principal);
            $existing = $member->memberRoles()->where('role_id', $role->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $memberRole = MemberRole::query()->create([
                'member_id' => $member->id,
                'role_id' => $role->id,
                'inherited_from' => null,
            ]);
            $this->expand($memberRole, []);

            return $memberRole->refresh();
        });
    }

    public function revokeRole(MemberRole $memberRole): void
    {
        if ($memberRole->inherited_from !== null) {
            throw new DomainException('Inherited member roles are removed with the role that granted them.');
        }

        DB::transaction(function () use ($memberRole): void {
            $this->deleteCascade($memberRole);
        });
    }

    public function addUserToGroup(User $group, User $user): void
    {
        if ($group->type !== User::TYPE_GROUP) {
            throw new DomainException('Principal is not a group.');
        }
        if ($user->type === User::TYPE_GROUP) {
            throw new DomainException('Groups cannot contain other groups.');
        }

        DB::transaction(function () use ($group, $user): void {
            $exists = DB::table('groups_users')
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->exists();
            if (! $exists) {
                DB::table('groups_users')->insert([
                    'group_id' => $group->id,
                    'user_id' => $user->id,
                ]);
            }

            $group->load('memberships.memberRoles.role', 'memberships.project');
            foreach ($group->memberships as $membership) {
                $project = $membership->project;
                if ($project === null) {
                    continue;
                }
                foreach ($membership->memberRoles as $memberRole) {
                    $role = $memberRole->role;
                    if ($role === null) {
                        continue;
                    }
                    $this->grantInherited($project, $user, $role, $memberRole, []);
                }
            }
        });
    }

    public function removeUserFromGroup(User $group, User $user): void
    {
        if ($group->type !== User::TYPE_GROUP) {
            throw new DomainException('Principal is not a group.');
        }

        DB::transaction(function () use ($group, $user): void {
            $groupRoleIds = MemberRole::query()
                ->select('member_roles.id')
                ->join('members', 'members.id', '=', 'member_roles.member_id')
                ->where('members.user_id', $group->id)
                ->pluck('member_roles.id');

            $directIds = MemberRole::query()
                ->select('member_roles.id')
                ->join('members', 'members.id', '=', 'member_roles.member_id')
                ->where('members.user_id', $user->id)
                ->whereIn('member_roles.inherited_from', $groupRoleIds)
                ->pluck('member_roles.id');

            foreach ($directIds as $id) {
                $memberRole = MemberRole::query()->find((int) $id);
                if ($memberRole !== null) {
                    $this->deleteCascade($memberRole);
                }
            }

            DB::table('groups_users')
                ->where('group_id', $group->id)
                ->where('user_id', $user->id)
                ->delete();
        });
    }

    public function copyInheritedFromParent(Project $project): void
    {
        if (! $project->inherit_members) {
            return;
        }

        $parent = $project->parent;
        if ($parent === null) {
            return;
        }

        DB::transaction(function () use ($project, $parent): void {
            $parent->load('members.memberRoles.role', 'members.user');
            foreach ($parent->members as $member) {
                $principal = $member->user;
                if ($principal === null) {
                    continue;
                }
                foreach ($member->memberRoles as $memberRole) {
                    $role = $memberRole->role;
                    if ($role === null || (int) $role->builtin !== BuiltinRole::CUSTOM) {
                        continue;
                    }
                    $this->grantInherited($project, $principal, $role, $memberRole, []);
                }
            }
        });
    }

    public function forgetCrossProjectInheritance(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $rows = MemberRole::query()
                ->select('member_roles.*')
                ->join('members', 'members.id', '=', 'member_roles.member_id')
                ->where('members.project_id', $project->id)
                ->whereNotNull('member_roles.inherited_from')
                ->get();

            $ids = [];
            foreach ($rows as $memberRole) {
                $source = MemberRole::query()->with('member')->find($memberRole->inherited_from);
                $sourceProjectId = $source?->member?->project_id;
                if ($sourceProjectId !== null && (int) $sourceProjectId !== (int) $project->id) {
                    $ids[] = (int) $memberRole->id;
                }
            }

            foreach ($ids as $id) {
                $memberRole = MemberRole::query()->find($id);
                if ($memberRole !== null) {
                    $this->deleteCascade($memberRole);
                }
            }
        });
    }

    public function isMember(User $user, Project $project): bool
    {
        return Member::query()
            ->where('project_id', $project->id)
            ->whereIn('user_id', $this->principalIds($user))
            ->exists();
    }

    /**
     * @return Collection<int, Role>
     */
    public function rolesOnProject(User $user, Project $project): Collection
    {
        return $this->rolesForPrincipals($this->principalIds($user), $project->id);
    }

    /**
     * @return Collection<int, Role>
     */
    public function rolesAcrossProjects(User $user): Collection
    {
        return $this->rolesForPrincipals($this->principalIds($user), null);
    }

    /**
     * @return list<int>
     */
    public function principalIds(User $user): array
    {
        return array_values(array_unique(array_merge([$user->id], $this->groupIds($user))));
    }

    /**
     * @return list<int>
     */
    public function groupIds(User $user): array
    {
        $ids = [];
        foreach ($user->groups()->pluck('users.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $visitedProjectIds
     */
    private function grantInherited(Project $project, User $principal, Role $role, MemberRole $source, array $visitedProjectIds): void
    {
        if ((int) $role->builtin !== BuiltinRole::CUSTOM) {
            return;
        }

        $member = $this->findOrCreateMember($project, $principal);
        $existing = $member->memberRoles()->where('role_id', $role->id)->first();
        if ($existing !== null) {
            return;
        }

        $memberRole = MemberRole::query()->create([
            'member_id' => $member->id,
            'role_id' => $role->id,
            'inherited_from' => $source->id,
        ]);
        $this->expand($memberRole, $visitedProjectIds);
    }

    /**
     * @param  list<int>  $visitedProjectIds
     */
    private function expand(MemberRole $memberRole, array $visitedProjectIds): void
    {
        $memberRole->loadMissing(['member.user', 'member.project', 'role']);
        $member = $memberRole->member;
        $principal = $member?->user;
        $project = $member?->project;
        $role = $memberRole->role;
        if ($member === null || $principal === null || $project === null || $role === null) {
            throw new DomainException('Member role is missing its member, principal, or role.');
        }

        if (! in_array($project->id, $visitedProjectIds, true)) {
            $visited = [...$visitedProjectIds, $project->id];
            $children = Project::query()
                ->where('parent_id', $project->id)
                ->where('inherit_members', true)
                ->orderBy('lft')
                ->get();
            foreach ($children as $child) {
                $this->grantInherited($child, $principal, $role, $memberRole, $visited);
            }
        }

        if ($principal->type === User::TYPE_GROUP) {
            $principal->load('groupUsers');
            foreach ($principal->groupUsers as $user) {
                $this->grantInherited($project, $user, $role, $memberRole, $visitedProjectIds);
            }
        }
    }

    private function deleteCascade(MemberRole $memberRole): void
    {
        $childIds = MemberRole::query()
            ->where('inherited_from', $memberRole->id)
            ->pluck('id');

        foreach ($childIds as $childId) {
            if (! is_numeric($childId)) {
                continue;
            }
            $child = MemberRole::query()->find((int) $childId);
            if ($child !== null) {
                $this->deleteCascade($child);
            }
        }

        $memberId = (int) $memberRole->member_id;
        if (MemberRole::query()->whereKey($memberRole->id)->exists()) {
            $memberRole->delete();
        }

        $remaining = MemberRole::query()->where('member_id', $memberId)->count();
        if ($remaining === 0) {
            Member::query()->whereKey($memberId)->delete();
        }
    }

    private function findOrCreateMember(Project $project, User $principal): Member
    {
        $member = Member::query()
            ->where('user_id', $principal->id)
            ->where('project_id', $project->id)
            ->first();
        if ($member !== null) {
            return $member;
        }

        return Member::query()->create([
            'user_id' => $principal->id,
            'project_id' => $project->id,
            'mail_notification' => false,
        ]);
    }

    /**
     * @param  list<int>  $principalIds
     * @return Collection<int, Role>
     */
    private function rolesForPrincipals(array $principalIds, ?int $projectId): Collection
    {
        if ($principalIds === []) {
            return new Collection;
        }

        $roleIds = DB::table('member_roles')
            ->join('members', 'members.id', '=', 'member_roles.member_id')
            ->whereIn('members.user_id', $principalIds)
            ->when($projectId !== null, function ($query) use ($projectId): void {
                $query->where('members.project_id', $projectId);
            })
            ->distinct()
            ->pluck('member_roles.role_id');

        $ids = [];
        foreach ($roleIds as $roleId) {
            if (is_numeric($roleId)) {
                $ids[] = (int) $roleId;
            }
        }

        if ($ids === []) {
            return new Collection;
        }

        return Role::query()->whereIn('id', $ids)->orderBy('position')->get();
    }
}
