<?php

namespace Tests\Unit;

use App\Domain\Acl\MembershipService;
use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryValidationException;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Version;
use App\Models\Watcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class IssueQueryFieldTest extends TestCase
{
    use RefreshDatabase;

    private DomainFixture $world;

    protected function setUp(): void
    {
        parent::setUp();
        $this->world = DomainFixture::boot('query-fields');
        $this->world->join();
    }

    public function test_author_group_and_role(): void
    {
        $group = User::factory()->create(['type' => User::TYPE_GROUP]);
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        app(MembershipService::class)->addUserToGroup($group, $member);
        $inside = $this->issue(['author_id' => $member->id, 'subject' => 'Inside']);
        $outside = $this->issue(['author_id' => $outsider->id, 'subject' => 'Outside']);

        $this->assertIds(['author.group' => $this->clause('=', [(string) $group->id])], [$inside->id]);
        $this->assertIds(['author.group' => $this->clause('!', [(string) $group->id])], [$outside->id]);
        $this->assertIds(['author.group' => $this->clause('*', [])], [$inside->id]);
        $this->assertIds(['author.group' => $this->clause('!*', [])], [$outside->id]);

        $mine = $this->issue(['subject' => 'Mine']);
        $this->assertIds(['author.role' => $this->clause('=', [(string) $this->world->role->id])], [$mine->id]);
        $this->assertContains($outside->id, $this->ids(['author.role' => $this->clause('!', [(string) $this->world->role->id])]));
        $this->assertNotContains($mine->id, $this->ids(['author.role' => $this->clause('!', [(string) $this->world->role->id])]));
    }

    public function test_assignee_group_and_role(): void
    {
        $group = User::factory()->create(['type' => User::TYPE_GROUP]);
        $member = User::factory()->create();
        $plain = User::factory()->create();
        app(MembershipService::class)->addUserToGroup($group, $member);
        app(MembershipService::class)->assignRole($this->world->project, $member, $this->world->role);
        $grouped = $this->issue(['assigned_to_id' => $member->id, 'subject' => 'Grouped']);
        $asGroup = $this->issue(['assigned_to_id' => $group->id, 'subject' => 'Group']);
        $other = $this->issue(['assigned_to_id' => $plain->id, 'subject' => 'Plain']);
        $blank = $this->issue(['assigned_to_id' => null, 'subject' => 'Blank']);

        $this->assertIds(['member_of_group' => $this->clause('=', [(string) $group->id])], [$grouped->id, $asGroup->id]);
        $this->assertIds(['member_of_group' => $this->clause('!', [(string) $group->id])], [$other->id, $blank->id]);
        $this->assertIds(['member_of_group' => $this->clause('*', [])], [$grouped->id, $asGroup->id]);
        $this->assertIds(['member_of_group' => $this->clause('!*', [])], [$other->id, $blank->id]);
        $this->assertIds(['assigned_to_role' => $this->clause('=', [(string) $this->world->role->id])], [$grouped->id]);
        $this->assertIds(['assigned_to_role' => $this->clause('!', [(string) $this->world->role->id])], [$asGroup->id, $other->id, $blank->id]);
        $this->assertIds(['assigned_to_role' => $this->clause('!*', [])], [$asGroup->id, $other->id, $blank->id]);
    }

    public function test_fixed_version_due_date_and_status(): void
    {
        $open = Version::query()->create([
            'name' => 'Open',
            'project_id' => $this->world->project->id,
            'status' => 'open',
            'effective_date' => '2026-10-06',
        ]);
        $locked = Version::query()->create([
            'name' => 'Locked',
            'project_id' => $this->world->project->id,
            'status' => 'locked',
            'effective_date' => null,
        ]);
        $scheduled = $this->issue(['fixed_version_id' => $open->id, 'subject' => 'Scheduled']);
        $held = $this->issue(['fixed_version_id' => $locked->id, 'subject' => 'Held']);
        $blank = $this->issue(['subject' => 'Blank']);

        $this->assertIds(['fixed_version.due_date' => $this->clause('=', ['2026-10-06'])], [$scheduled->id]);
        $this->assertIds(['fixed_version.due_date' => $this->clause('!*', [])], [$held->id, $blank->id]);
        $this->assertIds(['fixed_version.status' => $this->clause('=', ['open'])], [$scheduled->id]);
        $this->assertIds(['fixed_version.status' => $this->clause('!', ['open'])], [$held->id, $blank->id]);
    }

    public function test_project_status(): void
    {
        $issue = $this->issue(['subject' => 'Here']);
        $this->assertIds(['project.status' => $this->clause('=', ['1'])], [$issue->id]);
        $this->assertSame([], $this->ids(['project.status' => $this->clause('=', ['5'])]));
        $this->assertIds(['project.status' => $this->clause('!', ['5'])], [$issue->id]);
        $this->assertSame([], $this->ids(['project.status' => $this->clause('!', ['1'])]));
    }

    public function test_subproject_filter_includes_descendants(): void
    {
        $projects = app(ProjectService::class);
        $child = $projects->create([
            'name' => 'Child',
            'identifier' => 'query-fields-child',
            'is_public' => true,
        ], $this->world->project);
        $projects->enableModule($child, 'issue_tracking');
        app(MembershipService::class)->assignRole($child, $this->world->user, $this->world->role);
        $other = $projects->create([
            'name' => 'Elsewhere',
            'identifier' => 'query-fields-else',
            'is_public' => true,
        ]);
        $projects->enableModule($other, 'issue_tracking');
        app(MembershipService::class)->assignRole($other, $this->world->user, $this->world->role);

        $parent = $this->issue(['subject' => 'Parent']);
        $nested = $this->issue(['project_id' => $child->id, 'subject' => 'Nested']);
        $foreign = $this->issue(['project_id' => $other->id, 'subject' => 'Foreign']);

        $this->assertIds(['subproject_id' => $this->clause('*', [])], [$parent->id, $nested->id]);
        $this->assertIds(['subproject_id' => $this->clause('!*', [])], [$parent->id]);
        $this->assertIds(['subproject_id' => $this->clause('=', [(string) $child->id])], [$parent->id, $nested->id]);
        $this->assertIds(['subproject_id' => $this->clause('!', [(string) $child->id])], [$parent->id]);
        $this->assertIds(['subproject_id' => $this->clause('=', [(string) $other->id])], [$parent->id]);
        $this->assertNotContains($foreign->id, $this->ids(['subproject_id' => $this->clause('*', [])]));
        $roots = $this->ids(['subproject_id' => $this->clause('!*', [])], true);
        $this->assertContains($parent->id, $roots);
        $this->assertContains($foreign->id, $roots);
        $this->assertNotContains($nested->id, $roots);
        $this->expectRejection(['subproject_id' => $this->clause('~', ['x'])]);
    }

    public function test_notes_ignore_private_text_without_permission(): void
    {
        $hit = $this->issue(['subject' => 'Hit']);
        $private = $this->issue(['subject' => 'Private']);
        $quiet = $this->issue(['subject' => 'Quiet']);
        $this->note($hit, 'please ship the filter', false);
        $this->note($private, 'please ship secretly', true);

        $this->assertIds(['notes' => $this->clause('~', ['ship'])], [$hit->id]);
        $this->assertIds(['notes' => $this->clause('!~', ['ship'])], [$private->id, $quiet->id]);
        $this->assertIds(['notes' => $this->clause('!*', [])], [$private->id, $quiet->id]);

        $permissions = $this->world->role->permissions;
        $permissions[] = 'view_private_notes';
        $this->world->role->permissions = $permissions;
        $this->world->role->save();

        $this->assertIds(['notes' => $this->clause('~', ['secretly'])], [$private->id]);
        $visible = $this->ids(['notes' => $this->clause('^', ['please'])]);
        $this->assertContains($hit->id, $visible);
        $this->assertContains($private->id, $visible);
        $this->assertNotContains($quiet->id, $visible);
    }

    public function test_attachment_filename_and_description(): void
    {
        $file = $this->issue(['subject' => 'File']);
        $blank = $this->issue(['subject' => 'Blank']);
        Attachment::query()->create([
            'container_id' => $file->id,
            'container_type' => 'Issue',
            'filename' => 'spec.pdf',
            'disk_filename' => 'spec.pdf',
            'filesize' => 10,
            'description' => 'design notes',
            'author_id' => $this->world->user->id,
        ]);

        $this->assertIds(['attachment' => $this->clause('~', ['spec'])], [$file->id]);
        $this->assertIds(['attachment' => $this->clause('!*', [])], [$blank->id]);
        $this->assertIds(['attachment_description' => $this->clause('$', ['notes'])], [$file->id]);
        $this->assertIds(['attachment_description' => $this->clause('!~', ['design'])], [$blank->id]);
    }

    public function test_watchers_and_journal_authors(): void
    {
        $watched = $this->issue(['subject' => 'Watched']);
        $quiet = $this->issue(['subject' => 'Quiet']);
        $other = User::factory()->create();
        Watcher::query()->create([
            'watchable_id' => $watched->id,
            'watchable_type' => 'Issue',
            'user_id' => $this->world->user->id,
        ]);
        $this->note($watched, 'first', false, $other->id);
        $this->note($watched, 'second', false, $this->world->user->id);

        $this->assertIds(['watcher_id' => $this->clause('=', ['me'])], [$watched->id]);
        $this->assertIds(['watcher_id' => $this->clause('!*', [])], [$quiet->id]);
        $this->assertIds(['updated_by' => $this->clause('=', [(string) $other->id])], [$watched->id]);
        $this->assertIds(['updated_by' => $this->clause('!*', [])], [$quiet->id]);
        $this->assertIds(['last_updated_by' => $this->clause('=', ['me'])], [$watched->id]);
        $this->assertIds(['last_updated_by' => $this->clause('!', ['me'])], [$quiet->id]);
        $this->assertNotContains($watched->id, $this->ids(['last_updated_by' => $this->clause('=', [(string) $other->id])]));
    }

    public function test_spent_time_sums_hours(): void
    {
        $activity = Enumeration::query()->create([
            'name' => 'Design',
            'type' => 'TimeEntryActivity',
            'active' => true,
            'position' => 1,
        ]);
        $logged = $this->issue(['subject' => 'Logged']);
        $empty = $this->issue(['subject' => 'Empty']);
        TimeEntry::query()->create([
            'project_id' => $this->world->project->id,
            'issue_id' => $logged->id,
            'user_id' => $this->world->user->id,
            'author_id' => $this->world->user->id,
            'activity_id' => $activity->id,
            'hours' => 2,
            'spent_on' => '2026-09-29',
            'tmonth' => 9,
            'tweek' => 40,
            'tyear' => 2026,
        ]);
        TimeEntry::query()->create([
            'project_id' => $this->world->project->id,
            'issue_id' => $logged->id,
            'user_id' => $this->world->user->id,
            'author_id' => $this->world->user->id,
            'activity_id' => $activity->id,
            'hours' => 3,
            'spent_on' => '2026-09-29',
            'tmonth' => 9,
            'tweek' => 40,
            'tyear' => 2026,
        ]);

        $this->assertIds(['spent_time' => $this->clause('=', ['5'])], [$logged->id]);
        $this->assertIds(['spent_time' => $this->clause('>=', ['4'])], [$logged->id]);
        $this->assertIds(['spent_time' => $this->clause('<=', ['4'])], []);
        $this->assertIds(['spent_time' => $this->clause('><', ['1', '4'])], []);
        $this->assertIds(['spent_time' => $this->clause('!*', [])], [$empty->id]);
        $this->assertIds(['spent_time' => $this->clause('*', [])], [$logged->id]);
    }

    public function test_any_searchable_uses_subject_description_and_visible_custom_fields(): void
    {
        $subject = $this->issue(['subject' => 'Fix login', 'description' => '']);
        $custom = $this->issue(['subject' => 'Plain', 'description' => '']);
        $hiddenIssue = $this->issue(['subject' => 'Hidden value', 'description' => '']);
        $field = CustomField::query()->create([
            'name' => 'Search',
            'field_format' => 'string',
            'type' => 'IssueCustomField',
            'searchable' => true,
            'is_filter' => true,
            'is_for_all' => true,
            'visible' => true,
            'editable' => true,
            'position' => 1,
        ]);
        $secret = CustomField::query()->create([
            'name' => 'Secret',
            'field_format' => 'string',
            'type' => 'IssueCustomField',
            'searchable' => true,
            'is_filter' => true,
            'is_for_all' => true,
            'visible' => false,
            'editable' => true,
            'position' => 2,
        ]);
        CustomValue::query()->create([
            'custom_field_id' => $field->id,
            'customized_type' => 'Issue',
            'customized_id' => $custom->id,
            'value' => 'widget token',
        ]);
        CustomValue::query()->create([
            'custom_field_id' => $secret->id,
            'customized_type' => 'Issue',
            'customized_id' => $hiddenIssue->id,
            'value' => 'classified',
        ]);

        $this->assertIds(['any_searchable' => $this->clause('~', ['login'])], [$subject->id]);
        $this->assertIds(['any_searchable' => $this->clause('~', ['widget'])], [$custom->id]);
        $this->assertIds(['any_searchable' => $this->clause('*~', ['login widget'])], [$subject->id, $custom->id]);
        $this->assertSame([], $this->ids(['any_searchable' => $this->clause('~', ['classified'])]));
        $this->assertContains($hiddenIssue->id, $this->ids(['any_searchable' => $this->clause('!~', ['classified'])]));
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<int>  $expected
     */
    private function assertIds(array $filters, array $expected): void
    {
        $ids = $this->ids($filters);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @return list<int>
     */
    private function ids(array $filters, bool $global = false): array
    {
        $ids = [];
        $project = $global ? null : $this->world->project;
        $rows = app(IssueQueryRunner::class)->preview($this->world->user, $project, $filters)->pluck('id');
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     */
    private function expectRejection(array $filters): void
    {
        try {
            $this->ids($filters);
            $this->fail('Filter should have been rejected.');
        } catch (QueryValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  list<string>  $values
     * @return array{operator: string, values: list<string>}
     */
    private function clause(string $operator, array $values): array
    {
        return ['operator' => $operator, 'values' => $values];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issue(array $overrides): Issue
    {
        return Issue::query()->create([
            'project_id' => $this->world->project->id,
            'tracker_id' => $this->world->tracker->id,
            'status_id' => $this->world->newStatus->id,
            'priority_id' => $this->world->priority->id,
            'author_id' => $this->world->user->id,
            'subject' => 'Subject',
            'done_ratio' => 0,
            'is_private' => false,
            'lock_version' => 0,
            ...$overrides,
        ]);
    }

    private function note(Issue $issue, string $notes, bool $private, ?int $userId = null): void
    {
        Journal::query()->create([
            'journalized_id' => $issue->id,
            'journalized_type' => 'Issue',
            'user_id' => $userId ?? $this->world->user->id,
            'notes' => $notes,
            'private_notes' => $private,
        ]);
    }
}
