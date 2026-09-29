<?php

namespace App\Domain\Workflow;

use App\Domain\Acl\PermissionService;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Collection;

/**
 * Workflow transitions and field-permission merge.
 *
 * Transition rows use `workflows.type = WorkflowTransition`. New issues use
 * `old_status_id = 0`. Always-rows have author and assignee false. Author
 * and assignee rows are added when the user matches that side.
 */
final class WorkflowService
{
    public const TYPE_TRANSITION = 'WorkflowTransition';

    public const TYPE_FIELD_PERMISSION = 'WorkflowPermission';

    public const RULE_READONLY = 'readonly';

    public const RULE_REQUIRED = 'required';

    /**
     * Core field names that workflow permissions may constrain.
     * `parent_issue_id` is the rule name; the column is `parent_id`.
     *
     * @var array<string, string>
     */
    public const CORE_FIELD_COLUMNS = [
        'assigned_to_id' => 'assigned_to_id',
        'category_id' => 'category_id',
        'fixed_version_id' => 'fixed_version_id',
        'parent_issue_id' => 'parent_id',
        'start_date' => 'start_date',
        'due_date' => 'due_date',
        'estimated_hours' => 'estimated_hours',
        'done_ratio' => 'done_ratio',
        'description' => 'description',
        'priority_id' => 'priority_id',
    ];

    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @return list<int>
     */
    public function allowedStatusIds(
        ?User $user,
        Project $project,
        Tracker $tracker,
        int $oldStatusId,
        ?User $author,
        ?User $assignee,
    ): array {
        if ($user !== null && $user->admin && $user->isActive()) {
            $ids = [];
            foreach (IssueStatus::query()->orderBy('position')->pluck('id') as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }

            return $ids;
        }

        $roleIds = [];
        foreach ($this->permissions->rolesFor($user, $project) as $role) {
            $roleIds[] = (int) $role->id;
        }
        if ($roleIds === []) {
            return [];
        }

        $rows = Workflow::query()
            ->where('type', self::TYPE_TRANSITION)
            ->where('tracker_id', $tracker->id)
            ->whereIn('role_id', $roleIds)
            ->where('old_status_id', $oldStatusId)
            ->where('new_status_id', '>', 0)
            ->get();

        $isAuthor = $user !== null && $author !== null && $user->id === $author->id;
        $isAssignee = $this->userIsAssignee($user, $assignee);
        $ids = [];
        foreach ($rows as $row) {
            if (! $this->rowApplies($row, $isAuthor, $isAssignee)) {
                continue;
            }
            $ids[(int) $row->new_status_id] = (int) $row->new_status_id;
        }

        if ($ids === []) {
            return [];
        }

        $ordered = [];
        foreach (IssueStatus::query()->whereIn('id', array_values($ids))->orderBy('position')->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ordered[] = (int) $id;
            }
        }

        return $ordered;
    }

    public function allowsTransition(?User $user, Issue $issue, IssueStatus $newStatus): bool
    {
        if ((int) $issue->status_id === (int) $newStatus->id) {
            return true;
        }

        $project = $issue->project;
        $tracker = $issue->tracker;
        if ($project === null || $tracker === null) {
            return false;
        }

        $allowed = $this->allowedStatusIds(
            $user,
            $project,
            $tracker,
            (int) $issue->status_id,
            $issue->author,
            $issue->assignee,
        );

        return in_array((int) $newStatus->id, $allowed, true);
    }

    /**
     * @return Collection<int, IssueStatus>
     */
    public function allowedNextStatuses(?User $user, Issue $issue): Collection
    {
        $project = $issue->project;
        $tracker = $issue->tracker;
        if ($project === null || $tracker === null) {
            return new Collection;
        }

        $ids = $this->allowedStatusIds(
            $user,
            $project,
            $tracker,
            (int) $issue->status_id,
            $issue->author,
            $issue->assignee,
        );
        if (! in_array((int) $issue->status_id, $ids, true)) {
            $ids[] = (int) $issue->status_id;
        }

        return IssueStatus::query()->whereIn('id', $ids)->orderBy('position')->get();
    }

    /**
     * Merged field rule for the issue's current status.
     *
     * Null means the field is not constrained. Conflicting readonly/required
     * rules across every role resolve to required. A role with no row leaves
     * the field unconstrained.
     */
    public function fieldRule(?User $user, Issue $issue, string $fieldName): ?string
    {
        if ($user !== null && $user->admin && $user->isActive()) {
            return null;
        }

        $project = $issue->project;
        if ($project === null) {
            return null;
        }

        $roles = $this->permissions->rolesFor($user, $project);
        if ($roles->isEmpty()) {
            return null;
        }

        $roleIds = [];
        foreach ($roles as $role) {
            $roleIds[] = (int) $role->id;
        }

        $rows = Workflow::query()
            ->where('type', self::TYPE_FIELD_PERMISSION)
            ->where('tracker_id', $issue->tracker_id)
            ->whereIn('role_id', $roleIds)
            ->where('old_status_id', $issue->status_id)
            ->where('field_name', $fieldName)
            ->get();

        $ruleByRole = [];
        foreach ($rows as $row) {
            if ($row->rule !== self::RULE_READONLY && $row->rule !== self::RULE_REQUIRED) {
                continue;
            }
            $ruleByRole[(int) $row->role_id] = $row->rule;
        }

        $collected = [];
        foreach ($roleIds as $roleId) {
            if (! isset($ruleByRole[$roleId])) {
                return null;
            }
            $collected[] = $ruleByRole[$roleId];
        }

        if (in_array(self::RULE_REQUIRED, $collected, true)) {
            return self::RULE_REQUIRED;
        }

        return self::RULE_READONLY;
    }

    private function rowApplies(Workflow $row, bool $isAuthor, bool $isAssignee): bool
    {
        $authorOnly = (bool) $row->author;
        $assigneeOnly = (bool) $row->assignee;
        if (! $authorOnly && ! $assigneeOnly) {
            return true;
        }
        if ($authorOnly && $isAuthor) {
            return true;
        }

        return $assigneeOnly && $isAssignee;
    }

    private function userIsAssignee(?User $user, ?User $assignee): bool
    {
        if ($user === null || $assignee === null) {
            return false;
        }
        if ($user->id === $assignee->id) {
            return true;
        }
        if ($assignee->type !== User::TYPE_GROUP) {
            return false;
        }

        return $assignee->groupUsers()->where('users.id', $user->id)->exists();
    }
}
