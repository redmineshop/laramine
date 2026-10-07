<?php

namespace App\Domain\Issues;

use App\Domain\Acl\PermissionService;
use App\Domain\Acl\UserVisibility;
use App\Domain\CustomFields\CustomValueService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Workflow\WorkflowService;
use App\Domain\WorkflowDeniedException;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates issues inside a project, including subtask placement,
 * workflow checks, custom values, and journal rows for the update.
 */
final class IssueService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly UserVisibility $userVisibility,
        private readonly WorkflowService $workflows,
        private readonly IssueTree $trees,
        private readonly CustomValueService $customValues,
        private readonly IssueJournalWriter $journals,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, Project $project, array $attributes): Issue
    {
        if (! $this->permissions->allowed($actor, 'add_issues', $project)) {
            throw new PermissionDeniedException('add_issues');
        }

        $tracker = $this->tracker($project, $attributes);
        $subject = $this->subject($attributes);
        $assignee = $this->optionalAssignee($actor, $attributes['assigned_to_id'] ?? null, null);
        $status = $this->resolveCreateStatus($actor, $project, $tracker, $this->optionalInt($attributes, 'status_id'), $assignee);
        $priority = $this->priority($attributes);
        $parent = $this->optionalParent($attributes, $project, $actor, true);

        $isPrivate = array_key_exists('is_private', $attributes)
            ? (bool) $attributes['is_private']
            : (bool) $tracker->private_by_default;
        if ($isPrivate && array_key_exists('is_private', $attributes)) {
            $this->assertCanSetPrivate($actor, $project, $actor);
        }

        $payload = [
            'project_id' => $project->id,
            'tracker_id' => $tracker->id,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'author_id' => $actor->id,
            'assigned_to_id' => $assignee?->id,
            'subject' => $subject,
            'description' => $this->nullableString($attributes, 'description'),
            'start_date' => $this->nullableString($attributes, 'start_date'),
            'due_date' => $this->nullableString($attributes, 'due_date'),
            'estimated_hours' => $attributes['estimated_hours'] ?? null,
            'done_ratio' => $this->doneRatio($attributes, 0),
            'is_private' => $isPrivate,
            'lock_version' => 0,
        ];
        if ($status->is_closed) {
            $payload['closed_on'] = now();
        }

        $probe = new Issue($payload);
        $probe->setRelation('project', $project);
        $rulePayload = $payload;
        $rulePayload['parent_id'] = $parent?->id;
        $this->enforceFieldRules($actor, $probe, $rulePayload, []);
        $customInputs = $this->customFieldInputs($attributes);

        return DB::transaction(function () use ($actor, $parent, $payload, $customInputs): Issue {
            $issue = $parent === null
                ? $this->trees->createRoot($payload)
                : $this->trees->createChild($parent, $payload);
            $this->customValues->sync($actor, $issue, $customInputs, true);

            return $issue->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Issue $issue, array $attributes): Issue
    {
        $project = $issue->project;
        if ($project === null) {
            throw new DomainException('Issue has no project.');
        }

        $notes = $this->optionalNote($attributes);
        $privateNotes = $this->privateNotesRequested($attributes);
        if ($notes !== null && ! $this->permissions->allowed($actor, 'add_issue_notes', $project)) {
            throw new PermissionDeniedException('add_issue_notes');
        }
        if ($privateNotes && ! $this->permissions->allowed($actor, 'set_notes_private', $project)) {
            throw new PermissionDeniedException('set_notes_private');
        }

        $editing = $this->requestsAttributeEdit($attributes);
        $noteOnly = ! $editing && $notes !== null;
        if (! $noteOnly && ! $this->canEdit($actor, $issue, $project)) {
            throw new PermissionDeniedException('edit_issues');
        }

        $before = $this->journals->snapshot($issue);
        if (! $editing) {
            return DB::transaction(function () use ($actor, $issue, $before, $notes, $privateNotes): Issue {
                if ($notes !== null) {
                    $issue->touch();
                }

                return $this->persistJournal($actor, $issue, $before, $notes, $privateNotes);
            });
        }

        $current = [
            'subject' => $issue->subject,
            'description' => $issue->description,
            'start_date' => $this->dateString($issue->getAttribute('start_date')),
            'due_date' => $this->dateString($issue->getAttribute('due_date')),
            'estimated_hours' => $issue->estimated_hours,
            'done_ratio' => $issue->done_ratio,
            'priority_id' => $issue->priority_id,
            'assigned_to_id' => $issue->assigned_to_id,
            'parent_id' => $issue->parent_id,
            'is_private' => $issue->is_private,
            'status_id' => $issue->status_id,
        ];

        $nextStatus = null;
        if (array_key_exists('status_id', $attributes) && (int) $attributes['status_id'] !== (int) $issue->status_id) {
            $status = IssueStatus::query()->find($attributes['status_id']);
            if (! $status instanceof IssueStatus) {
                throw new DomainException('Status does not exist.');
            }
            if (! $this->workflows->allowsTransition($actor, $issue, $status)) {
                throw new WorkflowDeniedException('Status transition is not allowed.');
            }
            $nextStatus = $status;
        }

        if (array_key_exists('subject', $attributes)) {
            $issue->subject = $this->subject($attributes);
        }
        if (array_key_exists('description', $attributes)) {
            $issue->description = $this->nullableString($attributes, 'description');
        }
        if (array_key_exists('start_date', $attributes)) {
            $issue->start_date = $this->nullableString($attributes, 'start_date');
        }
        if (array_key_exists('due_date', $attributes)) {
            $issue->due_date = $this->nullableString($attributes, 'due_date');
        }
        if (array_key_exists('estimated_hours', $attributes)) {
            $issue->estimated_hours = $attributes['estimated_hours'];
        }
        if (array_key_exists('done_ratio', $attributes)) {
            $issue->done_ratio = $this->doneRatio($attributes, (int) $issue->done_ratio);
        }
        if (array_key_exists('priority_id', $attributes)) {
            $issue->priority_id = $this->priority($attributes)->id;
        }
        if (array_key_exists('assigned_to_id', $attributes)) {
            $assignee = $this->optionalAssignee($actor, $attributes['assigned_to_id'], $issue->assignee);
            $issue->assigned_to_id = $assignee?->id;
        }
        if (array_key_exists('is_private', $attributes) && (bool) $attributes['is_private'] !== (bool) $issue->is_private) {
            $author = $issue->author;
            if ($author === null) {
                throw new DomainException('Issue has no author.');
            }
            $this->assertCanSetPrivate($actor, $project, $author);
            $issue->is_private = (bool) $attributes['is_private'];
        }

        $parent = $issue->parent;
        $parentChanged = false;
        if (array_key_exists('parent_id', $attributes)) {
            [$parent, $parentChanged] = $this->changedParent($attributes, $issue, $project, $actor);
        }

        $incoming = [
            'description' => $issue->description,
            'start_date' => $this->dateString($issue->start_date),
            'due_date' => $this->dateString($issue->due_date),
            'estimated_hours' => $issue->estimated_hours,
            'done_ratio' => $issue->done_ratio,
            'priority_id' => $issue->priority_id,
            'assigned_to_id' => $issue->assigned_to_id,
            'parent_id' => $parent?->id,
        ];
        $this->enforceFieldRules($actor, $issue, $incoming, $current);
        $customInputs = $this->customFieldInputs($attributes);

        return DB::transaction(function () use ($actor, $issue, $parent, $parentChanged, $nextStatus, $customInputs, $before, $notes, $privateNotes): Issue {
            // Field rules use the status already stored on the issue.
            $this->customValues->sync($actor, $issue, $customInputs, false);
            if ($nextStatus !== null) {
                $issue->status_id = $nextStatus->id;
                $issue->closed_on = $nextStatus->is_closed ? ($issue->closed_on ?? now()) : null;
            }
            $issue->save();
            $saved = $parentChanged ? $this->trees->move($issue, $parent) : $issue;

            return $this->persistJournal($actor, $saved, $before, $notes, $privateNotes);
        });
    }

    /**
     * @param  array<string, string|null>  $before
     */
    private function persistJournal(User $actor, Issue $issue, array $before, ?string $notes, bool $privateNotes): Issue
    {
        $fresh = $issue->refresh();
        $this->journals->recordIssueUpdate(
            $actor,
            $fresh,
            $before,
            $this->journals->snapshot($fresh),
            $notes,
            $privateNotes,
        );

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function optionalNote(array $attributes): ?string
    {
        if (! array_key_exists('notes', $attributes) || $attributes['notes'] === null) {
            return null;
        }
        if (! is_string($attributes['notes'])) {
            throw new DomainException('Notes must be a string.');
        }
        $notes = trim($attributes['notes']);

        return $notes === '' ? null : $notes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function privateNotesRequested(array $attributes): bool
    {
        if (! array_key_exists('private_notes', $attributes) || $attributes['private_notes'] === null) {
            return false;
        }
        $value = $attributes['private_notes'];
        if ($value === false || $value === 0 || $value === '0') {
            return false;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }

        throw new DomainException('private_notes must be a boolean.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requestsAttributeEdit(array $attributes): bool
    {
        foreach ([
            'subject',
            'description',
            'start_date',
            'due_date',
            'estimated_hours',
            'done_ratio',
            'priority_id',
            'assigned_to_id',
            'is_private',
            'status_id',
            'parent_id',
            'custom_fields',
        ] as $key) {
            if (array_key_exists($key, $attributes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>
     */
    private function customFieldInputs(array $attributes): array
    {
        if (! array_key_exists('custom_fields', $attributes) || $attributes['custom_fields'] === null) {
            return [];
        }

        $inputs = $attributes['custom_fields'];
        if (! is_array($inputs) || ! array_is_list($inputs)) {
            throw new DomainException('custom_fields must be a list.');
        }

        $mapped = [];
        foreach ($inputs as $row) {
            if (! is_array($row) || ! array_key_exists('id', $row)) {
                throw new DomainException('Each custom field needs an id.');
            }
            if (! is_numeric($row['id'])) {
                throw new DomainException('Custom field id must be an integer.');
            }
            $id = (int) $row['id'];
            if (array_key_exists($id, $mapped)) {
                throw new DomainException('Custom field is repeated.');
            }
            $mapped[$id] = $row['value'] ?? null;
        }

        return $mapped;
    }

    private function canEdit(User $actor, Issue $issue, Project $project): bool
    {
        if ($actor->admin && $actor->isActive()) {
            return true;
        }
        if ($this->permissions->allowed($actor, 'edit_issues', $project)) {
            return true;
        }

        return (int) $issue->author_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_issues', $project);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tracker(Project $project, array $attributes): Tracker
    {
        $trackerId = $attributes['tracker_id'] ?? null;
        if (! is_numeric($trackerId)) {
            throw new DomainException('Tracker is required.');
        }
        $tracker = Tracker::query()->find((int) $trackerId);
        if (! $tracker instanceof Tracker) {
            throw new DomainException('Tracker does not exist.');
        }
        if (! $project->trackers()->where('trackers.id', $tracker->id)->exists()) {
            throw new DomainException('Tracker is not enabled on this project.');
        }

        return $tracker;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subject(array $attributes): string
    {
        $subject = $attributes['subject'] ?? null;
        if (! is_string($subject) || trim($subject) === '') {
            throw new DomainException('Subject is required.');
        }
        $subject = trim($subject);
        if (strlen($subject) > 255) {
            throw new DomainException('Subject must be 255 characters or fewer.');
        }

        return $subject;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function priority(array $attributes): Enumeration
    {
        if (array_key_exists('priority_id', $attributes) && $attributes['priority_id'] !== null) {
            if (! is_numeric($attributes['priority_id'])) {
                throw new DomainException('Priority does not exist.');
            }
            $priority = Enumeration::query()
                ->whereKey((int) $attributes['priority_id'])
                ->where('type', 'IssuePriority')
                ->first();
            if ($priority === null) {
                throw new DomainException('Priority does not exist.');
            }

            return $priority;
        }

        $priority = Enumeration::query()
            ->where('type', 'IssuePriority')
            ->where('active', true)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->first();
        if ($priority === null) {
            throw new DomainException('No issue priority is configured.');
        }

        return $priority;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function optionalParent(array $attributes, Project $project, User $actor, bool $creating): ?Issue
    {
        if (! array_key_exists('parent_id', $attributes) || $attributes['parent_id'] === null) {
            return null;
        }
        if (! is_numeric($attributes['parent_id'])) {
            throw new DomainException('Parent issue does not exist.');
        }
        if (! $actor->admin && ! $this->permissions->allowed($actor, 'manage_subtasks', $project)) {
            throw new PermissionDeniedException('manage_subtasks');
        }
        $parent = Issue::query()->find((int) $attributes['parent_id']);
        if ($parent === null) {
            throw new DomainException('Parent issue does not exist.');
        }
        if ((int) $parent->project_id !== (int) $project->id) {
            throw new DomainException('Subtasks must stay in the same project.');
        }
        if (! $creating && $parent->id === ($attributes['id'] ?? null)) {
            throw new DomainException('An issue cannot be its own parent.');
        }

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: ?Issue, 1: bool}
     */
    private function changedParent(array $attributes, Issue $issue, Project $project, User $actor): array
    {
        $currentId = $issue->parent_id !== null ? (int) $issue->parent_id : null;
        if ($attributes['parent_id'] === null) {
            if ($currentId === null) {
                return [null, false];
            }
            $this->assertCanManageSubtasks($actor, $project);

            return [null, true];
        }
        if (! is_numeric($attributes['parent_id'])) {
            throw new DomainException('Parent issue does not exist.');
        }
        $newId = (int) $attributes['parent_id'];
        if ($newId === $issue->id) {
            throw new DomainException('An issue cannot be its own parent.');
        }
        if ($newId === $currentId) {
            return [$issue->parent, false];
        }
        $this->assertCanManageSubtasks($actor, $project);
        $parent = Issue::query()->find($newId);
        if ($parent === null) {
            throw new DomainException('Parent issue does not exist.');
        }
        if ((int) $parent->project_id !== (int) $project->id) {
            throw new DomainException('Subtasks must stay in the same project.');
        }

        return [$parent, true];
    }

    private function assertCanManageSubtasks(User $actor, Project $project): void
    {
        if ($actor->admin && $actor->isActive()) {
            return;
        }
        if (! $this->permissions->allowed($actor, 'manage_subtasks', $project)) {
            throw new PermissionDeniedException('manage_subtasks');
        }
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    private function optionalAssignee(User $actor, mixed $id, ?User $already): ?User
    {
        if ($id === null) {
            return null;
        }
        if (! is_numeric($id)) {
            throw new DomainException('Assignee does not exist.');
        }
        $user = User::query()->find((int) $id);
        if ($user === null) {
            throw new DomainException('Assignee does not exist.');
        }
        if ($already !== null && (int) $already->id === (int) $user->id) {
            return $user;
        }
        if (! $this->userVisibility->canSee($actor, $user)) {
            throw new DomainException('Assignee is not visible.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function optionalInt(array $attributes, string $key): ?int
    {
        if (! array_key_exists($key, $attributes) || $attributes[$key] === null) {
            return null;
        }
        if (! is_numeric($attributes[$key])) {
            throw new DomainException('Status does not exist.');
        }

        return (int) $attributes[$key];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nullableString(array $attributes, string $key): ?string
    {
        if (! array_key_exists($key, $attributes) || $attributes[$key] === null || $attributes[$key] === '') {
            return null;
        }
        if (! is_string($attributes[$key]) && ! is_numeric($attributes[$key])) {
            throw new DomainException($key.' must be a string.');
        }

        return (string) $attributes[$key];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function doneRatio(array $attributes, int $fallback): int
    {
        if (! array_key_exists('done_ratio', $attributes) || $attributes['done_ratio'] === null) {
            return $fallback;
        }
        if (! is_numeric($attributes['done_ratio'])) {
            throw new DomainException('Done ratio must be between 0 and 100.');
        }
        $ratio = (int) $attributes['done_ratio'];
        if ($ratio < 0 || $ratio > 100) {
            throw new DomainException('Done ratio must be between 0 and 100.');
        }

        return $ratio;
    }

    private function resolveCreateStatus(User $actor, Project $project, Tracker $tracker, ?int $requestedId, ?User $assignee): IssueStatus
    {
        if ($actor->admin && $actor->isActive()) {
            $statusId = $requestedId ?? ($tracker->default_status_id !== null ? (int) $tracker->default_status_id : null);
            if ($statusId === null) {
                throw new DomainException('Tracker has no default status.');
            }
            $status = IssueStatus::query()->find($statusId);
            if (! $status instanceof IssueStatus) {
                throw new DomainException('Status does not exist.');
            }

            return $status;
        }

        $allowed = $this->workflows->allowedStatusIds($actor, $project, $tracker, 0, $actor, $assignee);
        if ($allowed === [] && $tracker->default_status_id !== null) {
            $allowed = [(int) $tracker->default_status_id];
        }

        if ($requestedId === null) {
            $defaultId = $tracker->default_status_id !== null ? (int) $tracker->default_status_id : null;
            if ($defaultId !== null && in_array($defaultId, $allowed, true)) {
                $requestedId = $defaultId;
            } elseif (count($allowed) === 1) {
                $requestedId = $allowed[0];
            } else {
                throw new WorkflowDeniedException('Status is required when more than one initial status is allowed.');
            }
        }

        if (! in_array($requestedId, $allowed, true)) {
            throw new WorkflowDeniedException('Status is not allowed for a new issue.');
        }

        $status = IssueStatus::query()->find($requestedId);
        if (! $status instanceof IssueStatus) {
            throw new DomainException('Status does not exist.');
        }

        return $status;
    }

    private function assertCanSetPrivate(User $actor, Project $project, User $author): void
    {
        if ($actor->admin && $actor->isActive()) {
            return;
        }
        if ($this->permissions->allowed($actor, 'set_issues_private', $project)) {
            return;
        }
        if ((int) $actor->id === (int) $author->id && $this->permissions->allowed($actor, 'set_own_issues_private', $project)) {
            return;
        }

        throw new PermissionDeniedException('set_issues_private');
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $previous
     */
    private function enforceFieldRules(User $actor, Issue $issue, array $incoming, array $previous): void
    {
        if ($actor->admin && $actor->isActive()) {
            return;
        }

        foreach (WorkflowService::CORE_FIELD_COLUMNS as $fieldName => $column) {
            if (! array_key_exists($column, $incoming)) {
                continue;
            }
            $rule = $this->workflows->fieldRule($actor, $issue, $fieldName);
            $value = $incoming[$column];
            $before = $previous[$column] ?? null;
            if ($rule === WorkflowService::RULE_READONLY && $this->changed($before, $value)) {
                throw new WorkflowDeniedException($fieldName.' is read-only in this status.');
            }
            if ($rule === WorkflowService::RULE_REQUIRED && $this->blank($value)) {
                throw new WorkflowDeniedException($fieldName.' is required in this status.');
            }
        }
    }

    private function changed(mixed $before, mixed $after): bool
    {
        if ($before instanceof DateTimeInterface) {
            $before = $before->format('Y-m-d');
        }
        if ($after instanceof DateTimeInterface) {
            $after = $after->format('Y-m-d');
        }
        if (is_float($before) || is_float($after)) {
            return (float) $before !== (float) $after;
        }

        return (string) ($before ?? '') !== (string) ($after ?? '');
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
