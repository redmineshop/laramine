<?php

namespace App\Domain\CustomFields;

use App\Domain\Acl\PermissionService;
use App\Domain\Acl\UserVisibility;
use App\Domain\CustomFields\Formats\UserFormat;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Users offered for a user-format custom field.
 *
 * Edit candidates are active project members, limited by `user_role`, then
 * limited to users the viewer can see (`users_visibility`). A record with no
 * project offers nobody. Groups are omitted. A previously stored id is not
 * added here.
 *
 * Filter candidates follow the issue-query author list: members of the
 * project and of visible descendants, or of every visible project when there
 * is no project. Locked users stay for an active admin. `user_role` is not
 * applied. `me` is a separate token for a logged-in active user.
 */
final class UserFieldOptions
{
    public function __construct(
        private readonly UserFormat $users,
        private readonly UserVisibility $visibility,
        private readonly PermissionService $permissions,
        private readonly CustomizedContext $context,
    ) {}

    /**
     * @return list<int>
     */
    public function offered(?User $viewer, CustomField $field, ?Model $record): array
    {
        $project = $record !== null ? $this->context->project($record) : null;
        if (! $project instanceof Project) {
            return [];
        }

        return $this->offeredOnProject($viewer, $field, $project);
    }

    /**
     * Intersection of edit candidates across records that have a project.
     * Records without a project are skipped. None left means an empty list.
     *
     * @param  list<Model>  $records
     * @return list<int>
     */
    public function offeredForRecords(?User $viewer, CustomField $field, array $records): array
    {
        $sets = [];
        foreach ($records as $record) {
            $project = $this->context->project($record);
            if (! $project instanceof Project) {
                continue;
            }
            $sets[] = $this->offeredOnProject($viewer, $field, $project);
        }
        if ($sets === []) {
            return [];
        }

        $common = $sets[0];
        foreach (array_slice($sets, 1) as $set) {
            $common = array_values(array_intersect($common, $set));
        }

        return $this->sortIds($common, false);
    }

    /**
     * True when the edit list repeats the viewer under a "me" label.
     */
    public function editMeLabel(?User $viewer, CustomField $field, ?Model $record): bool
    {
        if (! $viewer instanceof User || ! $viewer->isActive() || $viewer->type !== User::TYPE_USER) {
            return false;
        }

        return in_array((int) $viewer->id, $this->offered($viewer, $field, $record), true);
    }

    /**
     * @return array{ids: list<int>, me: bool}
     */
    public function filterList(?User $viewer, ?Project $project): array
    {
        $ids = $this->filterIds($viewer, $project);

        return [
            'ids' => $ids,
            'me' => $viewer instanceof User && $viewer->isActive() && $viewer->type === User::TYPE_USER,
        ];
    }

    /**
     * @return list<int>
     */
    private function offeredOnProject(?User $viewer, CustomField $field, Project $project): array
    {
        if ((string) $field->field_format !== 'user') {
            return [];
        }

        $roleIds = $this->users->roleLimit($field);
        if ($roleIds === null) {
            return [];
        }

        $memberIds = $this->memberUserIds((int) $project->id, $roleIds);
        if ($memberIds === []) {
            return [];
        }

        $active = [];
        $rows = User::query()
            ->whereIn('id', $memberIds)
            ->where('type', User::TYPE_USER)
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id');
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $active[] = (int) $id;
            }
        }

        return $this->sortIds($this->visibleIds($viewer, $active), false);
    }

    /**
     * @return list<int>
     */
    private function filterIds(?User $viewer, ?Project $project): array
    {
        $projectIds = $this->filterProjectIds($viewer, $project);
        if ($projectIds === []) {
            return $this->anonymousIds();
        }

        $memberIds = [];
        foreach (DB::table('members')->whereIn('project_id', $projectIds)->pluck('user_id') as $id) {
            if (is_numeric($id)) {
                $memberIds[] = (int) $id;
            }
        }
        if ($memberIds === []) {
            return $this->anonymousIds();
        }

        $principals = [];
        $rows = User::query()
            ->whereIn('id', $memberIds)
            ->whereIn('status', [User::STATUS_ACTIVE, User::STATUS_LOCKED])
            ->pluck('id');
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $principals[] = (int) $id;
            }
        }

        $sorted = $this->sortIds($this->visibleIds($viewer, $principals), true);
        foreach ($this->anonymousIds() as $id) {
            if (! in_array($id, $sorted, true)) {
                $sorted[] = $id;
            }
        }

        return $sorted;
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<int>
     */
    private function memberUserIds(int $projectId, array $roleIds): array
    {
        $query = DB::table('members')->where('members.project_id', $projectId);
        if ($roleIds !== []) {
            $query->join('member_roles', 'member_roles.member_id', '=', 'members.id')
                ->whereIn('member_roles.role_id', $roleIds);
        }

        $ids = [];
        foreach ($query->select('members.user_id')->distinct()->pluck('user_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function visibleIds(?User $viewer, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $visible = [];
        foreach ($this->visibility->apply(User::query()->whereIn('id', $ids), $viewer)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $visible[] = (int) $id;
            }
        }

        return $visible;
    }

    /**
     * @return list<int>
     */
    private function filterProjectIds(?User $viewer, ?Project $project): array
    {
        if (! $project instanceof Project) {
            $ids = [];
            foreach (Project::query()->orderBy('id')->get() as $candidate) {
                if ($this->permissions->projectVisible($viewer, $candidate)) {
                    $ids[] = (int) $candidate->id;
                }
            }

            return $ids;
        }

        $ids = [(int) $project->id];
        $left = (int) $project->lft;
        $right = (int) $project->rgt;
        $children = Project::query()
            ->where('lft', '>', $left)
            ->where('rgt', '<', $right)
            ->orderBy('lft')
            ->get();
        foreach ($children as $child) {
            if ($this->permissions->projectVisible($viewer, $child)) {
                $ids[] = (int) $child->id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function sortIds(array $ids, bool $byStatus): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = [];
        foreach (User::query()->whereIn('id', $ids)->where('type', User::TYPE_USER)->get() as $user) {
            $rows[] = $user;
        }

        usort($rows, function (User $left, User $right) use ($byStatus): int {
            if ($byStatus && (int) $left->status !== (int) $right->status) {
                return (int) $left->status <=> (int) $right->status;
            }
            $first = strcasecmp((string) $left->firstname, (string) $right->firstname);
            if ($first !== 0) {
                return $first;
            }
            $last = strcasecmp((string) $left->lastname, (string) $right->lastname);
            if ($last !== 0) {
                return $last;
            }

            return (int) $left->id <=> (int) $right->id;
        });

        $sorted = [];
        foreach ($rows as $user) {
            $sorted[] = (int) $user->id;
        }

        return $sorted;
    }

    /**
     * @return list<int>
     */
    private function anonymousIds(): array
    {
        $ids = [];
        foreach (User::query()->where('type', User::TYPE_ANONYMOUS)->orderBy('id')->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
