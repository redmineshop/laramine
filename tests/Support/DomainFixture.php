<?php

namespace Tests\Support;

use App\Domain\Acl\MembershipService;
use App\Domain\Projects\ProjectService;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;

/**
 * Shared project, tracker, and status rows for domain feature tests.
 */
final class DomainFixture
{
    public function __construct(
        public Project $project,
        public User $user,
        public Role $role,
        public Tracker $tracker,
        public Enumeration $priority,
        public IssueStatus $newStatus,
        public IssueStatus $inProgress,
        public IssueStatus $resolved,
        public IssueStatus $closed,
    ) {}

    public static function boot(string $identifier = 'demo'): self
    {
        $priority = Enumeration::query()->create([
            'name' => 'Normal',
            'type' => 'IssuePriority',
            'active' => true,
            'is_default' => true,
            'position' => 1,
        ]);
        $newStatus = IssueStatus::query()->create(['name' => 'New', 'is_closed' => false, 'position' => 1]);
        $inProgress = IssueStatus::query()->create(['name' => 'In Progress', 'is_closed' => false, 'position' => 2]);
        $resolved = IssueStatus::query()->create(['name' => 'Resolved', 'is_closed' => false, 'position' => 3]);
        $closed = IssueStatus::query()->create(['name' => 'Closed', 'is_closed' => true, 'position' => 4]);
        $tracker = Tracker::query()->create([
            'name' => 'Bug',
            'default_status_id' => $newStatus->id,
            'position' => 1,
        ]);

        $projects = app(ProjectService::class);
        $project = $projects->create([
            'name' => 'Demo',
            'identifier' => $identifier,
            'is_public' => true,
        ]);
        $projects->enableModule($project, 'issue_tracking');
        $projects->attachTracker($project, $tracker);

        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Developer',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => [
                'view_issues',
                'add_issues',
                'edit_issues',
                'edit_own_issues',
                'manage_subtasks',
                'add_issue_notes',
            ],
            'issues_visibility' => 'default',
        ]);

        return new self($project, $user, $role, $tracker, $priority, $newStatus, $inProgress, $resolved, $closed);
    }

    public function join(?User $user = null, ?Role $role = null): void
    {
        app(MembershipService::class)->assignRole($this->project, $user ?? $this->user, $role ?? $this->role);
    }
}
