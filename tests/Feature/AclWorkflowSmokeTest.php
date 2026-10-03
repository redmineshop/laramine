<?php

namespace Tests\Feature;

use App\Domain\Acl\BuiltinRole;
use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Domain\Workflow\WorkflowService;
use App\Domain\WorkflowDeniedException;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * MVP holes for projects, membership, workflow, and issues.
 *
 * Criteria already asserted by MembershipAclTest, IssueWorkflowTest,
 * PermissionCatalogTest, IssueQueryTest, and CustomFieldValueTest are not
 * repeated here. This file does not compare rows to a Redmine 7.0.1 database.
 * Parity stays NOT VERIFIED. See docs/acl-workflow-parity-gate.md.
 */
class AclWorkflowSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mvp_later_child_inherits_membership_and_group_sees_private_project(): void
    {
        $world = DomainFixture::boot('inherit');
        $world->join();
        $projects = app(ProjectService::class);
        $permissions = app(PermissionService::class);
        $memberships = app(MembershipService::class);

        $child = $projects->create([
            'name' => 'Later child',
            'identifier' => 'later-child',
            'inherit_members' => true,
        ], $world->project);
        $projects->enableModule($child, 'issue_tracking');

        $inherited = $this->memberRole($child, $world->user, $world->role);
        $this->assertNotNull($inherited, 'criterion 1');
        $this->assertNotNull($inherited->inherited_from, 'criterion 1');
        $this->assertTrue($permissions->allowed($world->user, 'add_issues', $child->fresh()), 'criterion 1');

        $private = $projects->create([
            'name' => 'Secret',
            'identifier' => 'secret',
            'is_public' => false,
        ]);
        $projects->enableModule($private, 'issue_tracking');
        $group = User::factory()->create([
            'type' => User::TYPE_GROUP,
            'firstname' => '',
            'lastname' => 'Ops',
        ]);
        $person = User::factory()->create();
        $memberships->addUserToGroup($group, $person);
        $memberships->assignRole($private, $group, $world->role);

        $throughGroup = $this->memberRole($private, $person, $world->role);
        $this->assertNotNull($throughGroup, 'criterion 2');
        $this->assertNotNull($throughGroup->inherited_from, 'criterion 2');
        $this->assertTrue($permissions->allowed($person, 'view_project', $private->fresh()), 'criterion 2');
        $this->assertTrue($permissions->allowed($person, 'add_issues', $private->fresh()), 'criterion 2');
        $this->assertFalse($permissions->allowed(User::factory()->create(), 'view_project', $private->fresh()), 'criterion 2');
    }

    public function test_mvp_inherit_off_keeps_same_project_group_role_and_blocks_direct_revoke(): void
    {
        $world = DomainFixture::boot('drop-inherit');
        $world->join();
        $projects = app(ProjectService::class);
        $memberships = app(MembershipService::class);
        $permissions = app(PermissionService::class);

        $child = $projects->create([
            'name' => 'Child',
            'identifier' => 'child',
            'inherit_members' => true,
        ], $world->project);
        $projects->enableModule($child, 'issue_tracking');
        $this->assertNotNull($this->memberRole($child, $world->user, $world->role), 'criterion 3 setup');

        $local = Role::query()->create([
            'name' => 'Local',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues'],
            'issues_visibility' => 'default',
        ]);
        $group = User::factory()->create([
            'type' => User::TYPE_GROUP,
            'firstname' => '',
            'lastname' => 'Local ops',
        ]);
        $memberships->addUserToGroup($group, $world->user);
        $source = $memberships->assignRole($child, $group, $local);
        $this->assertNull($source->inherited_from, 'criterion 3 setup');

        $projects->setInheritMembers($child->fresh(), false);
        $child = $child->fresh();
        $this->assertFalse($child->inherit_members, 'criterion 3');
        $this->assertNull($this->memberRole($child, $world->user, $world->role), 'criterion 3');
        $kept = $this->memberRole($child, $world->user, $local);
        $this->assertNotNull($kept, 'criterion 3');
        $this->assertNotNull($kept->inherited_from, 'criterion 3');
        $this->assertFalse($permissions->allowed($world->user->fresh(), 'add_issues', $child), 'criterion 3');
        $this->assertTrue($permissions->allowed($world->user->fresh(), 'view_issues', $child), 'criterion 3');

        try {
            $memberships->revokeRole($kept);
            $this->fail('An inherited member role is removed with its source.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Inherited', $exception->getMessage(), 'criterion 4');
        }
        $this->assertNotNull($this->memberRole($child, $world->user->fresh(), $local), 'criterion 4');

        $memberships->revokeRole($source->fresh());
        $this->assertNull($this->memberRole($child, $world->user->fresh(), $local), 'criterion 4');
        $this->assertFalse($permissions->allowed($world->user->fresh(), 'view_issues', $child->fresh()), 'criterion 4');
    }

    public function test_mvp_core_field_rules_merge_across_roles(): void
    {
        $world = DomainFixture::boot('merge');
        $world->join();
        $reporter = Role::query()->create([
            'name' => 'Reporter',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues', 'add_issues', 'edit_issues'],
            'issues_visibility' => 'default',
        ]);
        $reporterRole = app(MembershipService::class)->assignRole($world->project, $world->user, $reporter);
        $this->fieldRule($world, $world->role->id, 'due_date', WorkflowService::RULE_READONLY);
        $this->fieldRule($world, $reporter->id, 'due_date', WorkflowService::RULE_REQUIRED);
        $this->fieldRule($world, $world->role->id, 'start_date', WorkflowService::RULE_READONLY);

        $issues = app(IssueService::class);
        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Missing due date',
            ]);
            $this->fail('A required rule on any role blocks a blank due date.');
        } catch (WorkflowDeniedException $exception) {
            $this->assertStringContainsString('due_date', $exception->getMessage(), 'criterion 5');
        }
        $this->assertSame(0, $world->project->issues()->count(), 'criterion 5');

        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Dated',
            'due_date' => '2026-10-03',
        ]);
        try {
            $issues->update($world->user, $issue->fresh(), ['due_date' => null]);
            $this->fail('Clearing a required field on update is rejected.');
        } catch (WorkflowDeniedException) {
            $this->assertSame('2026-10-03', $this->day($issue->fresh()->due_date), 'criterion 5');
        }

        $dated = $issues->update($world->user, $issue->fresh(), ['start_date' => '2026-10-01']);
        $this->assertSame('2026-10-01', $this->day($dated->start_date), 'criterion 6');

        app(MembershipService::class)->revokeRole($reporterRole->fresh());
        try {
            $issues->update($world->user->fresh(), $dated->fresh(), ['start_date' => '2026-10-02']);
            $this->fail('Readonly applies once every remaining role has a row.');
        } catch (WorkflowDeniedException) {
            $this->assertSame('2026-10-01', $this->day($dated->fresh()->start_date), 'criterion 6');
        }
    }

    public function test_mvp_admin_bypasses_workflow_and_inactive_member_does_not(): void
    {
        $world = DomainFixture::boot('admin-bypass');
        $world->join();
        $this->fieldRule($world, $world->role->id, 'start_date', WorkflowService::RULE_READONLY);
        $issues = app(IssueService::class);
        $permissions = app(PermissionService::class);
        $admin = User::factory()->create(['admin' => true]);

        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Open',
        ]);
        try {
            $issues->update($world->user, $issue->fresh(), ['start_date' => '2026-10-01']);
            $this->fail('The member is still bound by the readonly rule.');
        } catch (WorkflowDeniedException) {
            $this->assertNull($issue->fresh()->start_date, 'criterion 7');
        }
        try {
            $issues->update($world->user, $issue->fresh(), ['status_id' => $world->closed->id]);
            $this->fail('edit_issues does not invent a missing transition.');
        } catch (WorkflowDeniedException) {
            $this->assertSame($world->newStatus->id, $issue->fresh()->status_id, 'criterion 7');
        }

        $edited = $issues->update($admin, $issue->fresh(), [
            'start_date' => '2026-10-01',
            'status_id' => $world->closed->id,
        ]);
        $this->assertSame('2026-10-01', $this->day($edited->start_date), 'criterion 7');
        $this->assertSame($world->closed->id, $edited->status_id, 'criterion 7');
        $this->assertNotNull($edited->closed_on, 'criterion 7');

        $closed = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Created closed',
            'status_id' => $world->closed->id,
        ]);
        $this->assertSame($world->closed->id, $closed->status_id, 'criterion 7');

        app(ProjectService::class)->disableModule($world->project, 'issue_tracking');
        $project = $world->project->fresh();
        $this->assertInstanceOf(Project::class, $project);
        $this->assertTrue($permissions->allowed($admin, 'view_issues', $project), 'criterion 8');
        $this->assertFalse($permissions->allowed($world->user->fresh(), 'view_issues', $project), 'criterion 8');

        $lockedAdmin = User::factory()->create(['admin' => true, 'status' => 3]);
        $this->assertFalse($permissions->allowed($lockedAdmin, 'view_issues', $project), 'criterion 8');

        app(ProjectService::class)->enableModule($world->project, 'issue_tracking');
        $reopened = $world->project->fresh();
        $this->assertInstanceOf(Project::class, $reopened);
        $world->user->status = 3;
        $world->user->save();
        $this->assertFalse($permissions->allowed($world->user->fresh(), 'add_issues', $reopened), 'criterion 9');
        $this->assertTrue($permissions->allowed($world->user->fresh(), 'view_project', $reopened), 'criterion 9');
    }

    public function test_mvp_assignee_group_transition_and_visibility(): void
    {
        $world = DomainFixture::boot('group-assignee');
        $memberships = app(MembershipService::class);
        $issues = app(IssueService::class);
        $visibility = app(IssueVisibility::class);

        $group = User::factory()->create([
            'type' => User::TYPE_GROUP,
            'firstname' => '',
            'lastname' => 'Assignees',
        ]);
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $memberships->addUserToGroup($group, $member);
        $memberships->assignRole($world->project, $group, $world->role);
        $memberships->assignRole($world->project, $outsider, $world->role);

        Workflow::query()->create([
            'type' => WorkflowService::TYPE_TRANSITION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $world->role->id,
            'old_status_id' => $world->resolved->id,
            'new_status_id' => $world->closed->id,
            'author' => false,
            'assignee' => true,
        ]);

        $admin = User::factory()->create(['admin' => true]);
        $secret = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Assigned to the group',
            'status_id' => $world->resolved->id,
            'assigned_to_id' => $group->id,
            'is_private' => true,
        ]);

        $this->assertTrue($visibility->canSee($member, $secret), 'criterion 11');
        $this->assertFalse($visibility->canSee($outsider, $secret), 'criterion 11');

        try {
            $issues->update($outsider, $secret->fresh(), ['status_id' => $world->closed->id]);
            $this->fail('edit_issues does not grant an assignee-only transition.');
        } catch (WorkflowDeniedException) {
            $this->assertSame($world->resolved->id, $secret->fresh()->status_id, 'criterion 10');
        }

        $closed = $issues->update($member, $secret->fresh(), ['status_id' => $world->closed->id]);
        $this->assertSame($world->closed->id, $closed->status_id, 'criterion 10');

        $openRole = Role::query()->create([
            'name' => 'Sees all',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues'],
            'issues_visibility' => IssueVisibility::ALL,
        ]);
        $open = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Still private',
            'is_private' => true,
        ]);
        $this->assertFalse($visibility->canSee($outsider->fresh(), $open), 'criterion 12');
        $granted = $memberships->assignRole($world->project, $outsider, $openRole);
        $this->assertSame(
            IssueVisibility::ALL,
            $visibility->effective(app(PermissionService::class)->rolesFor($outsider->fresh(), $world->project)),
            'criterion 12',
        );
        $this->assertTrue($visibility->canSee($outsider->fresh(), $open->fresh()), 'criterion 12');
        $memberships->revokeRole($granted);
        $this->assertFalse($visibility->canSee($outsider->fresh(), $open->fresh()), 'criterion 12');
    }

    public function test_mvp_edit_own_issues_and_private_flag(): void
    {
        $world = DomainFixture::boot('own-edit');
        $authorRole = Role::query()->create([
            'name' => 'Author',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues', 'add_issues', 'edit_own_issues', 'set_own_issues_private'],
            'issues_visibility' => 'default',
        ]);
        $editorRole = Role::query()->create([
            'name' => 'Editor',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues', 'edit_issues', 'set_own_issues_private'],
            'issues_visibility' => 'default',
        ]);
        $plainRole = Role::query()->create([
            'name' => 'Plain',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues', 'add_issues', 'edit_own_issues'],
            'issues_visibility' => 'default',
        ]);

        $memberships = app(MembershipService::class);
        $author = $world->user;
        $other = User::factory()->create();
        $editor = User::factory()->create();
        $plain = User::factory()->create();
        $memberships->assignRole($world->project, $author, $authorRole);
        $memberships->assignRole($world->project, $other, $authorRole);
        $memberships->assignRole($world->project, $editor, $editorRole);
        $memberships->assignRole($world->project, $plain, $plainRole);

        Workflow::query()->create([
            'type' => WorkflowService::TYPE_TRANSITION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $authorRole->id,
            'old_status_id' => $world->newStatus->id,
            'new_status_id' => $world->inProgress->id,
            'author' => false,
            'assignee' => false,
        ]);
        Workflow::query()->create([
            'type' => WorkflowService::TYPE_TRANSITION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $authorRole->id,
            'old_status_id' => $world->inProgress->id,
            'new_status_id' => $world->closed->id,
            'author' => false,
            'assignee' => true,
        ]);

        $issues = app(IssueService::class);
        $issue = $issues->create($author, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Mine',
        ]);
        $renamed = $issues->update($author, $issue, ['subject' => 'Mine renamed']);
        $this->assertSame('Mine renamed', $renamed->subject, 'criterion 14');

        try {
            $issues->update($other, $renamed->fresh(), ['subject' => 'Not mine']);
            $this->fail('edit_own_issues does not edit another author.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame('edit_issues', $exception->permission, 'criterion 14');
            $this->assertSame('Mine renamed', $renamed->fresh()->subject, 'criterion 14');
        }

        $moved = $issues->update($author, $renamed->fresh(), ['status_id' => $world->inProgress->id]);
        $this->assertSame($world->inProgress->id, $moved->status_id, 'criterion 15');
        try {
            $issues->update($author, $moved->fresh(), ['status_id' => $world->closed->id]);
            $this->fail('edit_own_issues does not grant an assignee-only transition.');
        } catch (WorkflowDeniedException) {
            $this->assertSame($world->inProgress->id, $moved->fresh()->status_id, 'criterion 15');
        }

        $private = $issues->update($author, $moved->fresh(), ['is_private' => true]);
        $this->assertTrue($private->is_private, 'criterion 16');

        $shared = $issues->create($author, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Shared',
        ]);
        try {
            $issues->update($editor, $shared->fresh(), ['is_private' => true]);
            $this->fail('set_own_issues_private does not cover another author.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame('set_issues_private', $exception->permission, 'criterion 16');
            $this->assertFalse($shared->fresh()->is_private, 'criterion 16');
        }

        try {
            $issues->create($plain, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Cannot hide',
                'is_private' => true,
            ]);
            $this->fail('Creating a private issue requires a private permission.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame('set_issues_private', $exception->permission, 'criterion 16');
        }
    }

    public function test_mvp_builtin_roles_filter_issue_visibility(): void
    {
        $world = DomainFixture::boot('builtins');
        $nonMember = Role::query()->create([
            'name' => 'Non member',
            'builtin' => BuiltinRole::NON_MEMBER,
            'assignable' => false,
            'permissions' => ['view_issues'],
            'issues_visibility' => IssueVisibility::DEFAULT,
        ]);
        $anonymous = Role::query()->create([
            'name' => 'Anonymous',
            'builtin' => BuiltinRole::ANONYMOUS,
            'assignable' => false,
            'permissions' => ['view_issues'],
            'issues_visibility' => IssueVisibility::DEFAULT,
        ]);

        $admin = User::factory()->create(['admin' => true]);
        $issues = app(IssueService::class);
        $publicIssue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Public',
        ]);
        $privateIssue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Private',
            'is_private' => true,
        ]);

        $visitor = User::factory()->create();
        $visibility = app(IssueVisibility::class);
        $this->assertTrue($visibility->canSee($visitor, $publicIssue), 'criterion 13');
        $this->assertFalse($visibility->canSee($visitor, $privateIssue), 'criterion 13');
        $this->assertTrue($visibility->canSee(null, $publicIssue), 'criterion 13');
        $this->assertFalse($visibility->canSee(null, $privateIssue), 'criterion 13');

        $nonMember->issues_visibility = IssueVisibility::OWN;
        $nonMember->save();
        $this->assertFalse($visibility->canSee($visitor->fresh(), $publicIssue->fresh()), 'criterion 13');

        $anonymous->issues_visibility = IssueVisibility::ALL;
        $anonymous->save();
        $this->assertTrue($visibility->canSee(null, $privateIssue->fresh()), 'criterion 13');
    }

    public function test_mvp_stored_deferrals_do_not_change_checks(): void
    {
        $world = DomainFixture::boot('defer');
        $memberships = app(MembershipService::class);
        $permissions = app(PermissionService::class);

        $nonMember = Role::query()->create([
            'name' => 'Non member',
            'builtin' => BuiltinRole::NON_MEMBER,
            'permissions' => ['view_issues'],
        ]);
        try {
            $memberships->assignRole($world->project, $world->user, $nonMember);
            $this->fail('Builtin roles are not assigned through members.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Builtin', $exception->getMessage(), 'criterion 17');
        }
        $this->assertFalse($memberships->isMember($world->user, $world->project), 'criterion 17');

        $world->join();
        $world->role->settings = [
            'permissions_all_trackers' => ['add_issues' => '0'],
            'permissions_tracker_ids' => ['add_issues' => [999]],
        ];
        $world->role->save();
        $stored = $world->role->fresh()?->getRawOriginal('settings');
        $this->assertIsString($stored, 'criterion 18');
        $this->assertStringStartsWith('{', $stored, 'criterion 18');
        $this->assertTrue(
            $permissions->allowed($world->user->fresh(), 'add_issues', $world->project->fresh()),
            'criterion 18',
        );

        $managed = Role::query()->create([
            'name' => 'Managed',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues'],
        ]);
        $unmanaged = Role::query()->create([
            'name' => 'Unmanaged',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues', 'add_issue_notes'],
        ]);
        $world->role->all_roles_managed = false;
        $world->role->save();
        $world->role->managedRoles()->attach($managed->id);
        $this->assertTrue(
            $world->role->fresh()?->managedRoles()->where('roles.id', $managed->id)->exists(),
            'criterion 19',
        );

        $someone = User::factory()->create();
        $memberships->assignRole($world->project, $someone, $unmanaged);
        $this->assertTrue(
            $permissions->allowed($someone, 'add_issue_notes', $world->project->fresh()),
            'criterion 19',
        );

        try {
            $permissions->allowed($world->user, 'not_a_permission', $world->project);
            $this->fail('Unknown permission names fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unknown permission', $exception->getMessage(), 'criterion 20');
        }
    }

    private function fieldRule(DomainFixture $world, int $roleId, string $field, string $rule): void
    {
        Workflow::query()->create([
            'type' => WorkflowService::TYPE_FIELD_PERMISSION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $roleId,
            'old_status_id' => $world->newStatus->id,
            'new_status_id' => 0,
            'field_name' => $field,
            'rule' => $rule,
        ]);
    }

    private function memberRole(Project $project, User $user, Role $role): ?MemberRole
    {
        $row = MemberRole::query()
            ->select('member_roles.*')
            ->join('members', 'members.id', '=', 'member_roles.member_id')
            ->where('members.project_id', $project->id)
            ->where('members.user_id', $user->id)
            ->where('member_roles.role_id', $role->id)
            ->first();

        return $row instanceof MemberRole ? $row : null;
    }

    private function day(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }

        return null;
    }
}
