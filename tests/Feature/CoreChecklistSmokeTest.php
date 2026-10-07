<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Issues\IssueService;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Domain\Workflow\WorkflowService;
use App\Models\EnabledModule;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * MySQL 8 happy paths for the core checklist acceptance section in
 * docs/acl-workflow-parity-gate.md. A green run is Laramine behavior.
 * It is not a Redmine comparison.
 */
class CoreChecklistSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_child_module_and_tracker(): void
    {
        $projects = app(ProjectService::class);
        $parent = $projects->create([
            'name' => 'Parent',
            'identifier' => 'parent',
            'is_public' => true,
        ]);
        $child = $projects->create([
            'name' => 'Child',
            'identifier' => 'child',
            'is_public' => false,
        ], $parent);
        $tracker = Tracker::query()->create([
            'name' => 'Bug',
            'position' => 1,
        ]);

        $parent->refresh();
        $child->refresh();
        $projects->assertConsistent();
        $this->assertSame(1, $parent->lft);
        $this->assertSame(4, $parent->rgt);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(2, $child->lft);
        $this->assertSame(3, $child->rgt);
        $this->assertGreaterThan($parent->lft, $child->lft);
        $this->assertLessThan($parent->rgt, $child->rgt);

        $this->assertFalse($projects->moduleEnabled($child, 'issue_tracking'));
        $projects->enableModule($child, 'issue_tracking');
        $this->assertTrue($projects->moduleEnabled($child->fresh(), 'issue_tracking'));
        $this->assertSame(1, $child->enabledModules()->count());
        $module = $child->enabledModules()->first();
        $this->assertInstanceOf(EnabledModule::class, $module);
        $this->assertSame('issue_tracking', $module->name);

        $this->assertFalse($projects->hasTracker($child, $tracker));
        $projects->attachTracker($child, $tracker);
        $this->assertTrue($projects->hasTracker($child->fresh(), $tracker));
        $this->assertSame(1, $child->trackers()->count());
    }

    public function test_membership_assigns_role_and_allows_add_issues(): void
    {
        $world = DomainFixture::boot('members');
        $stranger = User::factory()->create();
        $permissions = app(PermissionService::class);

        $this->assertFalse($permissions->allowed($stranger, 'add_issues', $world->project));

        $memberRole = app(MembershipService::class)->assignRole($world->project, $world->user, $world->role);
        $member = Member::query()
            ->where('project_id', $world->project->id)
            ->where('user_id', $world->user->id)
            ->first();
        $this->assertInstanceOf(Member::class, $member);
        $this->assertSame($member->id, $memberRole->member_id);
        $this->assertSame($world->role->id, $memberRole->role_id);
        $this->assertNull($memberRole->inherited_from);
        $this->assertSame(1, MemberRole::query()->where('member_id', $member->id)->count());
        $this->assertTrue($permissions->allowed($world->user, 'add_issues', $world->project));

        $issue = app(IssueService::class)->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Member can add',
        ]);
        $this->assertSame($world->user->id, $issue->author_id);

        try {
            app(IssueService::class)->create($stranger, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Stranger cannot add',
            ]);
            $this->fail('A user with no membership is not allowed add_issues.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame('add_issues', $exception->permission);
            $this->assertSame(1, $world->project->issues()->count());
        }
    }

    public function test_workflow_allows_non_default_initial_status_and_next_status(): void
    {
        $world = DomainFixture::boot('workflow');
        $world->join();
        $this->transition($world, 0, $world->inProgress->id);
        $this->transition($world, $world->inProgress->id, $world->resolved->id);

        $issues = app(IssueService::class);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Starts in progress',
            'status_id' => $world->inProgress->id,
        ]);
        $this->assertSame($world->inProgress->id, $issue->status_id);
        $this->assertNotSame($world->tracker->default_status_id, $issue->status_id);

        $stored = $issue->fresh();
        $this->assertNotNull($stored);
        $this->assertTrue(app(WorkflowService::class)->allowsTransition($world->user, $stored, $world->resolved));

        $updated = $issues->update($world->user, $stored, [
            'status_id' => $world->resolved->id,
        ]);
        $this->assertSame($world->resolved->id, $updated->status_id);
    }

    public function test_issue_create_stores_nested_set_and_update_writes_status_journal(): void
    {
        $world = DomainFixture::boot('issues');
        $world->join();
        $this->transition($world, $world->newStatus->id, $world->inProgress->id);

        $issues = app(IssueService::class);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Checklist bug',
            'description' => 'Opened by the member',
        ]);

        $this->assertSame($world->project->id, $issue->project_id);
        $this->assertSame($world->tracker->id, $issue->tracker_id);
        $this->assertSame($world->user->id, $issue->author_id);
        $this->assertSame($world->newStatus->id, $issue->status_id);
        $this->assertSame(1, $issue->lft);
        $this->assertSame(2, $issue->rgt);
        $this->assertSame($issue->id, $issue->root_id);
        $this->assertNull($issue->parent_id);
        $this->assertSame(0, $this->journals($issue->id)->count());

        $updated = $issues->update($world->user, $issue, [
            'status_id' => $world->inProgress->id,
        ]);
        $this->assertSame($world->inProgress->id, $updated->status_id);

        $journals = $this->journals($issue->id);
        $this->assertCount(1, $journals);
        $journal = $journals->first();
        $this->assertInstanceOf(Journal::class, $journal);
        $this->assertSame(IssueJournalWriter::JOURNALIZED_ISSUE, $journal->journalized_type);
        $this->assertSame($world->user->id, $journal->user_id);
        $this->assertNull($journal->notes);

        $detail = JournalDetail::query()->where('journal_id', $journal->id)->where('prop_key', 'status_id')->first();
        $this->assertInstanceOf(JournalDetail::class, $detail);
        $this->assertSame(IssueJournalWriter::PROPERTY_ATTR, $detail->property);
        $this->assertSame((string) $world->newStatus->id, $detail->old_value);
        $this->assertSame((string) $world->inProgress->id, $detail->value);
        $this->assertSame(1, JournalDetail::query()->where('journal_id', $journal->id)->count());
    }

    private function transition(DomainFixture $world, int $oldStatusId, int $newStatusId): void
    {
        Workflow::query()->create([
            'type' => WorkflowService::TYPE_TRANSITION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $world->role->id,
            'old_status_id' => $oldStatusId,
            'new_status_id' => $newStatusId,
            'author' => false,
            'assignee' => false,
        ]);
    }

    /**
     * @return Collection<int, Journal>
     */
    private function journals(int $issueId): Collection
    {
        return Journal::query()
            ->where('journalized_type', IssueJournalWriter::JOURNALIZED_ISSUE)
            ->where('journalized_id', $issueId)
            ->orderBy('id')
            ->get();
    }
}
