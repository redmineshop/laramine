<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\Issues\IssueService;
use App\Domain\Issues\IssueTree;
use App\Domain\Projects\ProjectService;
use App\Domain\TreeException;
use App\Domain\Workflow\WorkflowService;
use App\Domain\WorkflowDeniedException;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class IssueWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_transition_and_author_assignee_flags(): void
    {
        $world = DomainFixture::boot();
        $world->join();
        $this->transitions($world->tracker->id, $world->role->id, $world);

        $issues = app(IssueService::class);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'First bug',
        ]);
        $this->assertSame($world->newStatus->id, $issue->status_id);
        $this->assertSame(1, $issue->lft);
        $this->assertSame(2, $issue->rgt);
        $this->assertSame($issue->id, $issue->root_id);

        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Closed too soon',
                'status_id' => $world->closed->id,
            ]);
            $this->fail('A new issue cannot jump to a status without an old_status_id 0 row.');
        } catch (WorkflowDeniedException) {
            $this->assertSame(1, $world->project->issues()->count());
        }

        $issue = $issues->update($world->user, $issue, ['status_id' => $world->inProgress->id]);
        $issue = $issues->update($world->user, $issue, ['status_id' => $world->resolved->id]);

        try {
            $issues->update($world->user, $issue->fresh(), ['status_id' => $world->closed->id]);
            $this->fail('Closing is limited to the assignee.');
        } catch (WorkflowDeniedException) {
            $this->assertSame($world->resolved->id, $issue->fresh()->status_id);
        }

        try {
            $issues->update($world->user, $issue->fresh(), [
                'status_id' => $world->closed->id,
                'assigned_to_id' => $world->user->id,
            ]);
            $this->fail('Becoming the assignee in the same write does not grant the assignee transition.');
        } catch (WorkflowDeniedException) {
            $this->assertNull($issue->fresh()->assigned_to_id);
        }

        $reopened = $issues->update($world->user, $issue->fresh(), ['status_id' => $world->inProgress->id]);
        $this->assertSame($world->inProgress->id, $reopened->status_id);

        $assignee = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $assignee, $world->role);
        $resolved = $issues->update($world->user, $reopened, [
            'status_id' => $world->resolved->id,
            'assigned_to_id' => $assignee->id,
        ]);
        try {
            $issues->update($assignee, $resolved->fresh(), ['status_id' => $world->inProgress->id]);
            $this->fail('The assignee-only user is not the author, so the author transition is denied.');
        } catch (WorkflowDeniedException) {
            $this->assertSame($world->resolved->id, $resolved->fresh()->status_id);
        }

        $closed = $issues->update($assignee, $resolved->fresh(), ['status_id' => $world->closed->id]);
        $this->assertSame($world->closed->id, $closed->status_id);
        $this->assertNotNull($closed->closed_on);
    }

    public function test_subtasks_move_across_trees_and_field_rules(): void
    {
        $world = DomainFixture::boot('issues');
        $world->join();
        $this->transitions($world->tracker->id, $world->role->id, $world);
        Workflow::query()->create([
            'type' => WorkflowService::TYPE_FIELD_PERMISSION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $world->role->id,
            'old_status_id' => $world->newStatus->id,
            'new_status_id' => 0,
            'field_name' => 'due_date',
            'rule' => WorkflowService::RULE_REQUIRED,
        ]);
        Workflow::query()->create([
            'type' => WorkflowService::TYPE_FIELD_PERMISSION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $world->role->id,
            'old_status_id' => $world->newStatus->id,
            'new_status_id' => 0,
            'field_name' => 'start_date',
            'rule' => WorkflowService::RULE_READONLY,
        ]);

        $issues = app(IssueService::class);
        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Needs a due date',
            ]);
            $this->fail('due_date is required for this status.');
        } catch (WorkflowDeniedException $exception) {
            $this->assertStringContainsString('due_date', $exception->getMessage());
        }

        $parent = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Parent',
            'due_date' => '2026-10-01',
        ]);
        $child = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Child',
            'due_date' => '2026-10-02',
            'parent_id' => $parent->id,
        ]);
        $other = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Other root',
            'due_date' => '2026-10-03',
        ]);

        $trees = app(IssueTree::class);
        $trees->assertConsistent();
        $this->assertSame($parent->id, $child->root_id);
        $this->assertSame($other->id, $other->root_id);

        try {
            $issues->update($world->user, $parent->fresh(), ['parent_id' => $child->id]);
            $this->fail('A parent cannot move under its descendant.');
        } catch (TreeException) {
            $trees->assertConsistent();
            $this->assertSame($parent->id, $child->fresh()->parent_id);
        }

        $moved = $issues->update($world->user, $child->fresh(), ['parent_id' => $other->id]);
        $trees->assertConsistent();
        $this->assertSame($other->id, $moved->root_id);
        $this->assertSame($other->id, $moved->parent_id);
        $this->assertSame(1, $parent->fresh()->lft);
        $this->assertSame(2, $parent->fresh()->rgt);

        $extracted = $issues->update($world->user, $moved, ['parent_id' => null]);
        $trees->assertConsistent();
        $this->assertNull($extracted->parent_id);
        $this->assertSame($extracted->id, $extracted->root_id);
        $this->assertSame(1, $extracted->lft);

        try {
            $issues->update($world->user, $parent->fresh(), ['start_date' => '2026-09-01']);
            $this->fail('start_date is read-only.');
        } catch (WorkflowDeniedException) {
            $this->assertNull($parent->fresh()->start_date);
        }

        $noted = $issues->update($world->user, $parent->fresh(), ['description' => 'Still open']);
        $this->assertSame('Still open', $noted->description);
    }

    public function test_subtask_parent_must_be_in_the_same_project(): void
    {
        $world = DomainFixture::boot('left');
        $world->join();
        $issues = app(IssueService::class);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Here',
        ]);
        $projects = app(ProjectService::class);
        $other = $projects->create(['name' => 'Other', 'identifier' => 'other']);
        $projects->enableModule($other, 'issue_tracking');
        $projects->attachTracker($other, $world->tracker);
        $otherUser = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Other developer',
            'builtin' => 0,
            'permissions' => ['add_issues', 'view_issues', 'edit_issues', 'manage_subtasks'],
        ]);
        app(MembershipService::class)->assignRole($other, $otherUser, $role);
        $foreign = $issues->create($otherUser, $other, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'There',
        ]);

        $this->expectExceptionMessage('same project');
        $issues->update($world->user, $issue, ['parent_id' => $foreign->id]);
    }

    private function transitions(int $trackerId, int $roleId, DomainFixture $world): void
    {
        $rows = [
            [0, $world->newStatus->id, false, false],
            [$world->newStatus->id, $world->inProgress->id, false, false],
            [$world->inProgress->id, $world->resolved->id, false, false],
            [$world->resolved->id, $world->closed->id, false, true],
            [$world->resolved->id, $world->inProgress->id, true, false],
        ];
        foreach ($rows as [$old, $new, $author, $assignee]) {
            Workflow::query()->create([
                'type' => WorkflowService::TYPE_TRANSITION,
                'tracker_id' => $trackerId,
                'role_id' => $roleId,
                'old_status_id' => $old,
                'new_status_id' => $new,
                'author' => $author,
                'assignee' => $assignee,
            ]);
        }
    }
}
