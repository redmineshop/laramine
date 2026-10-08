<?php

namespace App\Domain\Api;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Issues\IssueDeletion;
use App\Domain\Issues\IssueRelationService;
use App\Domain\Issues\IssueService;
use App\Domain\Queries\JournalVisibility;
use App\Domain\TimeEntries\IssueSpentHours;
use App\Domain\Workflow\WorkflowService;
use App\Http\Api\ApiCall;
use App\Http\Api\ApiPage;
use App\Http\Api\ApiQuery;
use App\Http\Api\ApiResult;
use App\Models\Attachment;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\User;
use App\Models\Watcher;
use Illuminate\Http\Request;

/**
 * Issue list, show, create, update, and delete for the REST API.
 */
final class IssueApi
{
    public function __construct(
        private readonly ApiCall $calls,
        private readonly ApiValues $values,
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $visibility,
        private readonly IssueService $issues,
        private readonly IssueDeletion $deletion,
        private readonly IssueSpentHours $hours,
        private readonly WorkflowService $workflows,
        private readonly JournalVisibility $journals,
    ) {}

    public function index(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $page = ApiPage::from($request);
            $project = $this->scopedProject($actor, $request);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $issues = $this->visibleIssues($actor, $project);
            $slice = $page->slice($issues);
            $rows = [];
            foreach ($slice['rows'] as $issue) {
                $rows[] = $this->document($actor, $issue, []);
            }

            return ApiResult::ok($this->calls->collection('issues', $rows, $page, $slice['total']));
        });
    }

    public function show(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $issue = $this->readable($actor, $id);
            if ($issue instanceof ApiResult) {
                return $issue;
            }

            return ApiResult::ok([
                'issue' => $this->document($actor, $issue, ApiQuery::includes($request)),
            ]);
        });
    }

    public function store(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $attributes = ApiQuery::resource($request, 'issue');
            $project = $this->projectFromAttributes($actor, $attributes, $request);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $issue = $this->issues->create($actor, $project, $attributes);

            return ApiResult::created(['issue' => $this->document($actor, $issue->refresh(), [])]);
        });
    }

    public function update(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $issue = $this->readable($actor, $id);
            if ($issue instanceof ApiResult) {
                return $issue;
            }
            $attributes = ApiQuery::resource($request, 'issue');
            $this->issues->update($actor, $issue, $attributes);

            return ApiResult::noContent();
        });
    }

    public function destroy(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $issue = $this->readable($actor, $id);
            if ($issue instanceof ApiResult) {
                return $issue;
            }
            $this->deletion->delete($actor, $issue);

            return ApiResult::noContent();
        });
    }

    /**
     * @param  array<string, true>  $includes
     * @return array<string, mixed>
     */
    public function document(User $actor, Issue $issue, array $includes): array
    {
        $issue->loadMissing(['project', 'tracker', 'status', 'priority', 'author', 'assignee', 'category', 'fixedVersion']);
        $project = $issue->project;
        $tracker = $issue->tracker;
        $status = $issue->status;
        $priority = $issue->priority;
        $author = $issue->author;

        $row = [
            'id' => (int) $issue->id,
            'project' => $project instanceof Project ? $this->values->ref((int) $project->id, (string) $project->name) : null,
            'tracker' => $tracker !== null ? $this->values->ref((int) $tracker->id, (string) $tracker->name) : null,
            'status' => $this->statusRef($status),
            'priority' => $priority instanceof Enumeration ? $this->values->ref((int) $priority->id, (string) $priority->name) : null,
            'author' => $author instanceof User ? $this->values->ref((int) $author->id, $this->values->personName($author)) : null,
        ];
        $assignee = $issue->assignee;
        if ($assignee instanceof User) {
            $row['assigned_to'] = $this->values->ref((int) $assignee->id, $this->values->personName($assignee));
        }
        $category = $issue->category;
        if ($category !== null) {
            $row['category'] = $this->values->ref((int) $category->id, (string) $category->name);
        }
        $version = $issue->fixedVersion;
        if ($version !== null) {
            $row['fixed_version'] = $this->values->ref((int) $version->id, (string) $version->name);
        }
        if ($issue->parent_id !== null) {
            $row['parent'] = ['id' => (int) $issue->parent_id];
        }

        $row['subject'] = (string) $issue->subject;
        $row['description'] = $this->values->text($issue->description);
        $row['start_date'] = $this->values->day($issue->getAttribute('start_date'));
        $row['due_date'] = $this->values->day($issue->getAttribute('due_date'));
        $row['done_ratio'] = (int) $issue->done_ratio;
        $row['is_private'] = (bool) $issue->is_private;
        $row['estimated_hours'] = $issue->estimated_hours === null ? null : (float) $issue->estimated_hours;
        $row['total_estimated_hours'] = $this->totalEstimated($issue);
        $row['spent_hours'] = $this->hours->spent($issue);
        $row['total_spent_hours'] = $this->hours->total($issue);
        $row['custom_fields'] = $this->values->customFields($actor, $issue);
        $row['created_on'] = $this->values->stamp($issue->created_on);
        $row['updated_on'] = $this->values->stamp($issue->updated_on);
        $row['closed_on'] = $this->values->stamp($issue->closed_on);

        if (isset($includes['children'])) {
            $row['children'] = $this->children($issue);
        }
        if (isset($includes['attachments'])) {
            $row['attachments'] = $this->attachments($issue);
        }
        if (isset($includes['relations'])) {
            $row['relations'] = $this->relations($issue);
        }
        if (isset($includes['journals'])) {
            $row['journals'] = $this->journalRows($actor, $issue);
        }
        if (isset($includes['watchers'])) {
            $row['watchers'] = $this->watchers($issue);
        }
        if (isset($includes['allowed_statuses'])) {
            $row['allowed_statuses'] = $this->allowedStatuses($actor, $issue);
        }

        return $row;
    }

    private function scopedProject(User $actor, Request $request): Project|ApiResult|null
    {
        $raw = $request->query('project_id');
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->findVisibleProject($actor, $raw);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function projectFromAttributes(User $actor, array $attributes, Request $request): Project|ApiResult
    {
        $raw = $attributes['project_id'] ?? $request->query('project_id');
        if ($raw === null || $raw === '') {
            return ApiResult::fail(422, 'Project is required.');
        }
        $project = $this->findVisibleProject($actor, $raw);

        return $project instanceof Project ? $project : $project;
    }

    private function findVisibleProject(User $actor, mixed $raw): Project|ApiResult
    {
        $project = $this->locateProject($raw);
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }

        return $project;
    }

    private function locateProject(mixed $raw): ?Project
    {
        if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
            $found = Project::query()->find((int) $raw);

            return $found instanceof Project ? $found : null;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $found = Project::query()->where('identifier', $raw)->first();

        return $found instanceof Project ? $found : null;
    }

    /**
     * @return list<Issue>
     */
    private function visibleIssues(User $actor, ?Project $project): array
    {
        if ($project instanceof Project) {
            if (! $project->isModuleEnabled('issue_tracking')) {
                return [];
            }
            $query = Issue::query()->orderByDesc('issues.id');
            $this->visibility->apply($query, $actor, $project);

            return array_values($query->get()->all());
        }

        $ids = [];
        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if (! $candidate->isModuleEnabled('issue_tracking')) {
                continue;
            }
            if (! $this->permissions->projectVisible($actor, $candidate)) {
                continue;
            }
            $query = Issue::query();
            $this->visibility->apply($query, $actor, $candidate);
            foreach ($query->pluck('issues.id') as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        if ($ids === []) {
            return [];
        }

        return array_values(Issue::query()->whereIn('id', $ids)->orderByDesc('id')->get()->all());
    }

    private function readable(User $actor, int $id): Issue|ApiResult
    {
        $issue = Issue::query()->find($id);
        if (! $issue instanceof Issue) {
            return ApiResult::fail(404, 'Not found');
        }
        $project = $issue->project;
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }
        if (! $project->isModuleEnabled('issue_tracking') || ! $this->visibility->canSee($actor, $issue)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $issue;
    }

    /**
     * @return array{id: int, name: string, is_closed: bool}|null
     */
    private function statusRef(?IssueStatus $status): ?array
    {
        if (! $status instanceof IssueStatus) {
            return null;
        }

        return [
            'id' => (int) $status->id,
            'name' => (string) $status->name,
            'is_closed' => (bool) $status->is_closed,
        ];
    }

    private function totalEstimated(Issue $issue): float
    {
        $sum = Issue::query()
            ->where('root_id', $issue->root_id)
            ->where('lft', '>=', $issue->lft)
            ->where('rgt', '<=', $issue->rgt)
            ->sum('estimated_hours');

        return (float) $sum;
    }

    /**
     * @return list<array{id: int, tracker: array{id: int, name: string}|null, subject: string}>
     */
    private function children(Issue $issue): array
    {
        $rows = [];
        $children = Issue::query()->where('parent_id', $issue->id)->orderBy('lft')->orderBy('id')->get();
        foreach ($children as $child) {
            $child->loadMissing('tracker');
            $tracker = $child->tracker;
            $rows[] = [
                'id' => (int) $child->id,
                'tracker' => $tracker !== null ? $this->values->ref((int) $tracker->id, (string) $tracker->name) : null,
                'subject' => (string) $child->subject,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(Issue $issue): array
    {
        $rows = [];
        $attachments = Attachment::query()
            ->where('container_type', 'Issue')
            ->where('container_id', $issue->id)
            ->orderBy('id')
            ->get();
        foreach ($attachments as $attachment) {
            $attachment->loadMissing('author');
            $author = $attachment->author;
            $rows[] = [
                'id' => (int) $attachment->id,
                'filename' => (string) $attachment->filename,
                'filesize' => (int) $attachment->filesize,
                'content_type' => $attachment->content_type === null ? null : (string) $attachment->content_type,
                'description' => $this->values->text($attachment->description),
                'content_url' => url('/attachments/'.$attachment->id),
                'author' => $author instanceof User ? $this->values->ref((int) $author->id, $this->values->personName($author)) : null,
                'created_on' => $this->values->stamp($attachment->created_on),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relations(Issue $issue): array
    {
        $rows = [];
        $relations = IssueRelation::query()
            ->where('issue_from_id', $issue->id)
            ->orWhere('issue_to_id', $issue->id)
            ->orderBy('id')
            ->get();
        foreach ($relations as $relation) {
            $rows[] = $this->relationFrom($issue, $relation);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function relationFrom(Issue $issue, IssueRelation $relation): array
    {
        $forward = (int) $relation->issue_from_id === (int) $issue->id;
        $type = (string) $relation->relation_type;
        if (! $forward) {
            $type = IssueRelationService::REVERSE[$type] ?? $type;
        }

        return [
            'id' => (int) $relation->id,
            'issue_id' => (int) $issue->id,
            'issue_to_id' => $forward ? (int) $relation->issue_to_id : (int) $relation->issue_from_id,
            'relation_type' => $type,
            'delay' => $relation->delay === null ? null : (int) $relation->delay,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journalRows(User $actor, Issue $issue): array
    {
        $project = $issue->project;
        $rows = [];
        $journals = Journal::query()
            ->where('journalized_type', 'Issue')
            ->where('journalized_id', $issue->id)
            ->orderBy('id')
            ->get();
        foreach ($journals as $journal) {
            if ($journal->private_notes && $project instanceof Project && ! $this->canSeePrivate($actor, $project)) {
                continue;
            }
            $journal->loadMissing('user');
            $user = $journal->user;
            $details = [];
            $stored = JournalDetail::query()->where('journal_id', $journal->id)->orderBy('id')->get();
            foreach ($stored as $detail) {
                $details[] = [
                    'property' => (string) $detail->property,
                    'name' => (string) $detail->prop_key,
                    'old_value' => $detail->old_value === null ? null : (string) $detail->old_value,
                    'new_value' => $detail->value === null ? null : (string) $detail->value,
                ];
            }
            $rows[] = [
                'id' => (int) $journal->id,
                'user' => $user instanceof User ? $this->values->ref((int) $user->id, $this->values->personName($user)) : null,
                'notes' => $journal->notes === null ? null : (string) $journal->notes,
                'created_on' => $this->values->stamp($journal->created_on),
                'private_notes' => (bool) $journal->private_notes,
                'details' => $details,
            ];
        }

        return $rows;
    }

    private function canSeePrivate(User $actor, Project $project): bool
    {
        if ($actor->admin && $actor->isActive()) {
            return true;
        }

        return $this->journals->sql($actor, $project) === '1 = 1';
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function watchers(Issue $issue): array
    {
        $rows = [];
        $watchers = Watcher::query()
            ->where('watchable_type', 'Issue')
            ->where('watchable_id', $issue->id)
            ->orderBy('id')
            ->get();
        foreach ($watchers as $watcher) {
            $user = User::query()->find($watcher->user_id);
            if ($user instanceof User) {
                $rows[] = $this->values->ref((int) $user->id, $this->values->personName($user));
            }
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, name: string, is_closed: bool}>
     */
    private function allowedStatuses(User $actor, Issue $issue): array
    {
        /** @var array<int, IssueStatus> $statuses */
        $statuses = [];
        $current = $issue->status;
        if ($current instanceof IssueStatus) {
            $statuses[(int) $current->id] = $current;
        }
        foreach ($this->workflows->allowedNextStatuses($actor, $issue) as $status) {
            $statuses[(int) $status->id] = $status;
        }
        uasort($statuses, static function (IssueStatus $left, IssueStatus $right): int {
            $position = ((int) $left->position) <=> ((int) $right->position);

            return $position === 0 ? ((int) $left->id) <=> ((int) $right->id) : $position;
        });
        $rows = [];
        foreach ($statuses as $status) {
            $ref = $this->statusRef($status);
            if ($ref !== null) {
                $rows[] = $ref;
            }
        }

        return $rows;
    }
}
