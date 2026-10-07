<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryColumns;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Queries\SavedQueryService;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueQuery;
use App\Models\Journal;
use App\Models\Project;
use App\Models\ProjectQuery;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class IssueQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_and_runs_the_spec_fixtures(): void
    {
        $world = $this->member();
        $otherTracker = $this->tracker($world, 'Feature');
        $list = CustomField::query()->create([
            'name' => 'Database',
            'field_format' => 'list',
            'type' => 'IssueCustomField',
            'possible_values' => ['MySQL', 'PostgreSQL'],
            'is_filter' => true,
            'is_for_all' => true,
            'visible' => true,
        ]);
        $assignee = User::factory()->create();

        $fixLogin = $this->issue($world, ['subject' => 'Fix login']);
        $docs = $this->issue($world, ['tracker_id' => $otherTracker->id, 'subject' => 'Docs']);
        $old = $this->issue($world, ['status_id' => $world->closed->id, 'subject' => 'Old bug']);
        $second = $this->secondProject($world);
        $fixApi = $this->issue($world, [
            'project_id' => $second->id,
            'subject' => 'Fix API',
            'assigned_to_id' => $assignee->id,
        ]);
        foreach ([$fixLogin, $old, $fixApi] as $issue) {
            CustomValue::query()->create([
                'custom_field_id' => $list->id,
                'customized_type' => 'Issue',
                'customized_id' => $issue->id,
                'value' => 'MySQL',
            ]);
        }
        CustomValue::query()->create([
            'custom_field_id' => $list->id,
            'customized_type' => 'Issue',
            'customized_id' => $docs->id,
            'value' => 'PostgreSQL',
        ]);

        $saved = app(SavedQueryService::class);
        $runner = app(IssueQueryRunner::class);
        $query = $saved->create($world->user, [
            'name' => 'Open bugs',
            'project_id' => null,
            'filters' => [
                ['field' => 'status_id', 'op' => 'o', 'values' => []],
            ],
            'sort_criteria' => [['id', 'asc']],
            'options' => ['display_type' => 'list'],
        ]);

        $this->assertSame(QueryType::ISSUE, $query->type);
        $this->assertSame(IssueQueryColumns::DEFAULT, $query->displayColumns());
        $this->assertSame(['display_type' => 'list'], $query->options);
        $this->assertSame(
            [$fixLogin->id, $docs->id, $fixApi->id],
            $this->ids($runner->execute($world->user, $query)),
        );

        $projectQuery = $saved->create($world->user, [
            'name' => 'Project open MySQL',
            'project_id' => $world->project->id,
            'filters' => [
                'status_id' => ['operator' => 'o', 'values' => []],
                'tracker_id' => ['operator' => '=', 'values' => [(string) $world->tracker->id]],
                'cf_'.$list->id => ['operator' => '=', 'values' => ['MySQL']],
                'subject' => ['operator' => '~', 'values' => ['Fix']],
                'assigned_to_id' => ['operator' => '!*', 'values' => []],
            ],
            'column_names' => ['subject', 'status'],
        ]);

        $this->assertSame(['subject', 'status'], $projectQuery->fresh()?->displayColumns());
        $this->assertSame([$fixLogin->id], $this->ids($runner->execute($world->user, $projectQuery)));
        $this->assertNotContains($old->id, $this->ids($runner->execute($world->user, $projectQuery)));
        $this->assertNotContains($fixApi->id, $this->ids($runner->execute($world->user, $projectQuery)));

        $stored = DB::table('queries')->where('id', $projectQuery->id)->value('filters');
        $this->assertIsString($stored);
        $this->assertStringStartsWith('{', ltrim($stored));
    }

    public function test_legacy_yaml_round_trips_to_json(): void
    {
        $world = $this->member();
        $open = $this->issue($world, ['subject' => 'Open']);
        $this->issue($world, ['status_id' => $world->closed->id, 'subject' => 'Closed']);

        $id = DB::table('queries')->insertGetId([
            'name' => 'Legacy',
            'type' => QueryType::ISSUE,
            'user_id' => $world->user->id,
            'project_id' => $world->project->id,
            'visibility' => 0,
            'filters' => "status_id:\n  :operator: o\n  :values: []\n",
            'column_names' => "---\n- :tracker\n- :subject\n",
            'sort_criteria' => "---\n- - :id\n  - asc\n",
        ]);

        $query = IssueQuery::query()->findOrFail($id);
        $this->assertSame(['tracker', 'subject'], $query->displayColumns());
        $this->assertSame([$open->id], $this->ids(app(IssueQueryRunner::class)->execute($world->user, $query)));

        $query->filters = $query->filters;
        $query->save();
        $stored = DB::table('queries')->where('id', $query->id)->value('filters');
        $this->assertIsString($stored);
        $this->assertStringContainsString('"operator":"o"', $stored);
    }

    public function test_visibility_private_roles_and_public(): void
    {
        $world = $this->member();
        $developer = $world->role;
        $reporter = Role::query()->create([
            'name' => 'Reporter',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues'],
            'issues_visibility' => 'default',
        ]);
        $reporterUser = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $reporterUser, $reporter);
        $outsider = User::factory()->create();
        $saved = app(SavedQueryService::class);

        $private = $saved->create($world->user, [
            'name' => 'Mine',
            'project_id' => $world->project->id,
            'visibility' => 0,
        ]);
        $roles = $saved->create($world->user, [
            'name' => 'Developers',
            'project_id' => $world->project->id,
            'visibility' => 1,
            'role_ids' => [$developer->id],
        ]);
        $public = $saved->create($world->user, [
            'name' => 'Everyone',
            'project_id' => $world->project->id,
            'visibility' => 2,
        ]);

        $this->assertTrue($saved->canView($world->user, $private));
        $this->assertFalse($saved->canView($reporterUser, $private));
        $this->assertFalse($saved->canView($outsider, $private));
        $this->assertTrue($saved->canView($world->user, $roles));
        $this->assertFalse($saved->canView($reporterUser, $roles));
        $this->assertTrue($saved->canView($reporterUser, $public));
        $this->assertTrue($saved->canView($outsider, $public));

        $visibleToReporter = $saved->visible($reporterUser, $world->project, QueryType::ISSUE)->pluck('id')->all();
        $this->assertSame([$public->id], $visibleToReporter);

        try {
            app(IssueQueryRunner::class)->execute($reporterUser, $private);
            $this->fail('A private query must not run for another user.');
        } catch (DomainException $exception) {
            $this->assertSame('Saved query is not visible.', $exception->getMessage());
        }

        $admin = User::factory()->create(['admin' => true]);
        $this->assertTrue($saved->canView($admin, $private));

        $saved->update($world->user, $roles, ['visibility' => 0]);
        $this->assertSame(0, DB::table('queries_roles')->where('query_id', $roles->id)->count());
        $this->assertFalse($saved->canView($reporterUser, $roles->fresh() ?? $roles));
    }

    public function test_owner_updates_and_deletes_and_others_cannot(): void
    {
        $world = $this->member();
        $other = User::factory()->create();
        $world->role->permissions = ['view_issues', 'add_issues', 'edit_issues', 'save_queries'];
        $world->role->save();
        app(MembershipService::class)->assignRole($world->project, $other, $world->role);

        $saved = app(SavedQueryService::class);
        $query = $saved->create($world->user, [
            'name' => 'Named',
            'project_id' => $world->project->id,
            'filters' => ['status_id' => ['operator' => 'o', 'values' => []]],
        ]);

        try {
            $saved->update($other, $query, ['name' => 'Stolen']);
            $this->fail('Another member must not update the query.');
        } catch (DomainException) {
            $this->assertSame('Named', $query->fresh()?->name);
        }

        $saved->update($world->user, $query, [
            'name' => 'Renamed',
            'filters' => ['subject' => ['operator' => '~', 'values' => ['Fix']]],
        ]);
        $this->assertSame('Renamed', $query->fresh()?->name);

        try {
            $saved->delete($other, $query);
            $this->fail('Another member must not delete the query.');
        } catch (DomainException) {
            $this->assertNotNull($query->fresh());
        }

        $saved->delete($world->user, $query);
        $this->assertNull(IssueQuery::query()->find($query->id));
    }

    public function test_negative_permission_returns_no_issues(): void
    {
        $world = $this->member();
        $open = $this->issue($world, ['subject' => 'Visible to members']);
        $denied = Role::query()->create([
            'name' => 'No issues',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_project'],
            'issues_visibility' => 'all',
        ]);
        $stranger = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $stranger, $denied);

        $ids = $this->ids(app(IssueQueryRunner::class)->preview(
            $stranger,
            $world->project,
            ['status_id' => ['operator' => 'o', 'values' => []]],
        ));

        $this->assertNotContains($open->id, $ids);
        $this->assertSame([], $ids);

        $world->role->permissions = ['view_issues'];
        $world->role->save();
        try {
            app(SavedQueryService::class)->create($world->user, [
                'name' => 'Blocked',
                'project_id' => $world->project->id,
            ]);
            $this->fail('save_queries is required.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame('save_queries', $exception->permission);
        }
    }

    public function test_private_issues_and_own_visibility_hide_other_rows(): void
    {
        $world = $this->member();
        $other = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $other, $world->role);
        $private = $this->issue($world, ['subject' => 'Secret', 'is_private' => true]);
        $shared = $this->issue($world, ['subject' => 'Shared', 'author_id' => $other->id]);

        $runner = app(IssueQueryRunner::class);
        $filters = ['status_id' => ['operator' => '*', 'values' => []]];
        $this->assertEqualsCanonicalizing(
            [$private->id, $shared->id],
            $this->ids($runner->preview($world->user, $world->project, $filters)),
        );
        $this->assertSame(
            [$shared->id],
            $this->ids($runner->preview($other, $world->project, $filters)),
        );

        $world->role->issues_visibility = 'own';
        $world->role->save();
        $this->assertSame(
            [$private->id],
            $this->ids($runner->preview($world->user->fresh(), $world->project->fresh(), $filters)),
        );
    }

    public function test_saved_query_runs_notes_and_subprojects(): void
    {
        $world = $this->member();
        $noted = $this->issue($world, ['subject' => 'Noted']);
        $quiet = $this->issue($world, ['subject' => 'Quiet']);
        Journal::query()->create([
            'journalized_id' => $noted->id,
            'journalized_type' => 'Issue',
            'user_id' => $world->user->id,
            'notes' => 'please ship',
            'private_notes' => false,
        ]);
        $saved = app(SavedQueryService::class);
        $notes = $saved->create($world->user, [
            'name' => 'Notes',
            'project_id' => $world->project->id,
            'filters' => ['notes' => ['operator' => '~', 'values' => ['ship']]],
        ]);
        $this->assertSame([$noted->id], $this->ids(app(IssueQueryRunner::class)->execute($world->user, $notes)));
        $this->assertNotContains($quiet->id, $this->ids(app(IssueQueryRunner::class)->execute($world->user, $notes)));

        $child = app(ProjectService::class)->create([
            'name' => 'Child',
            'identifier' => 'query-feature-child',
            'is_public' => true,
        ], $world->project);
        app(ProjectService::class)->enableModule($child, 'issue_tracking');
        app(MembershipService::class)->assignRole($child, $world->user, $world->role);
        $nested = $this->issue($world, ['project_id' => $child->id, 'subject' => 'Nested']);
        $wide = $saved->create($world->user, [
            'name' => 'Tree',
            'project_id' => $world->project->id,
            'filters' => ['subproject_id' => ['operator' => '*', 'values' => []]],
        ]);
        $narrow = $saved->create($world->user, [
            'name' => 'Here',
            'project_id' => $world->project->id,
            'filters' => ['subproject_id' => ['operator' => '!*', 'values' => []]],
        ]);
        $wideIds = $this->ids(app(IssueQueryRunner::class)->execute($world->user, $wide));
        $narrowIds = $this->ids(app(IssueQueryRunner::class)->execute($world->user, $narrow));
        $this->assertContains($nested->id, $wideIds);
        $this->assertContains($noted->id, $wideIds);
        $this->assertNotContains($nested->id, $narrowIds);
        $this->assertContains($noted->id, $narrowIds);
    }

    public function test_sort_and_stub_types(): void
    {
        $world = $this->member();
        $world->priority->position = 4;
        $world->priority->save();
        $urgent = Enumeration::query()->create([
            'name' => 'Urgent',
            'type' => 'IssuePriority',
            'active' => true,
            'position' => 1,
        ]);
        $byPosition = $this->issue($world, ['priority_id' => $urgent->id, 'subject' => 'Urgent']);
        $byForeignKey = $this->issue($world, ['subject' => 'Normal']);

        $ids = $this->ids(app(IssueQueryRunner::class)->preview(
            $world->user,
            $world->project,
            [],
            [['priority', 'asc'], ['id', 'asc']],
        ));
        $this->assertSame([$byPosition->id, $byForeignKey->id], $ids);

        $stub = app(SavedQueryService::class)->create($world->user, [
            'name' => 'Projects',
            'type' => QueryType::PROJECT,
            'project_id' => $world->project->id,
        ]);
        $this->assertInstanceOf(ProjectQuery::class, ProjectQuery::query()->find($stub->id));

        try {
            app(IssueQueryRunner::class)->totals($world->user, $stub);
            $this->fail('Stub queries cannot be totaled.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Only IssueQuery can be executed.', $exception->getMessage());
        }

        try {
            app(SavedQueryService::class)->create($world->user, [
                'name' => 'Bad stub',
                'type' => QueryType::PROJECT,
                'project_id' => $world->project->id,
                'filters' => ['status_id' => ['operator' => 'o', 'values' => []]],
            ]);
            $this->fail('Stub queries cannot store issue filters.');
        } catch (QueryValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_totals_sum_the_visible_issue_set(): void
    {
        $world = $this->member();
        $world->role->permissions = [
            'view_issues',
            'add_issues',
            'edit_issues',
            'save_queries',
            'view_time_entries',
        ];
        $world->role->time_entries_visibility = 'all';
        $world->role->save();
        $other = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $other, $world->role);
        $activity = Enumeration::query()->create([
            'name' => 'Design',
            'type' => 'TimeEntryActivity',
            'active' => true,
            'position' => 1,
        ]);
        $points = CustomField::query()->create([
            'name' => 'Points',
            'field_format' => 'int',
            'type' => 'IssueCustomField',
            'is_filter' => true,
            'is_for_all' => true,
            'visible' => true,
        ]);
        $weight = CustomField::query()->create([
            'name' => 'Weight',
            'field_format' => 'float',
            'type' => 'IssueCustomField',
            'is_filter' => false,
            'is_for_all' => true,
            'visible' => true,
        ]);

        $open = $this->issue($world, ['subject' => 'Open', 'estimated_hours' => 2.5]);
        $blank = $this->issue($world, ['subject' => 'Blank']);
        $signed = $this->issue($world, ['subject' => 'Signed']);
        $doubled = $this->issue($world, ['subject' => 'Doubled']);
        $private = $this->issue($world, ['subject' => 'Secret', 'is_private' => true, 'estimated_hours' => 7]);
        $closed = $this->issue($world, [
            'subject' => 'Closed',
            'status_id' => $world->closed->id,
            'estimated_hours' => 9,
        ]);
        $childProject = app(ProjectService::class)->create([
            'name' => 'Child',
            'identifier' => 'query-totals-child',
            'is_public' => true,
            'parent_id' => $world->project->id,
        ], $world->project);
        app(ProjectService::class)->enableModule($childProject, 'issue_tracking');
        app(MembershipService::class)->assignRole($childProject, $world->user, $world->role);
        app(MembershipService::class)->assignRole($childProject, $other, $world->role);
        $child = $this->issue($world, ['project_id' => $childProject->id, 'subject' => 'Child', 'estimated_hours' => 1]);
        $elsewhere = $this->secondProject($world);
        $outside = $this->issue($world, [
            'project_id' => $elsewhere->id,
            'subject' => 'Elsewhere',
            'estimated_hours' => 100,
        ]);

        $this->hours($world, $activity, $open, 2);
        $this->hours($world, $activity, $open, 3);
        $this->hours($world, $activity, $closed, 4);
        $this->hours($world, $activity, $outside, 50);

        $this->custom($points, $open, '10');
        $this->custom($points, $open, '12abc');
        $this->custom($points, $private, '3');
        $this->custom($points, $closed, '100');
        $this->custom($points, $doubled, '4');
        $this->custom($points, $doubled, '6');
        $this->custom($weight, $open, '1.5');
        $this->custom($weight, $blank, '2.25');
        $this->custom($weight, $signed, '-0.25');

        $saved = app(SavedQueryService::class);
        $runner = app(IssueQueryRunner::class);
        $query = $saved->create($world->user, [
            'name' => 'Totals',
            'project_id' => $world->project->id,
            'visibility' => 2,
            'filters' => ['status_id' => ['operator' => 'o', 'values' => []]],
            'options' => [
                'display_type' => 'list',
                'totalable_names' => [
                    'estimated_hours',
                    'spent_hours',
                    'cf_'.$points->id,
                    'cf_'.$weight->id,
                ],
            ],
        ]);

        $expectedForAuthor = [
            'estimated_hours' => '10.5',
            'spent_hours' => '5',
            'cf_'.$points->id => '23',
            'cf_'.$weight->id => '3.5',
        ];
        $this->assertSame($expectedForAuthor, $runner->totals($world->user, $query));
        $this->assertSame([
            'estimated_hours' => '3.5',
            'spent_hours' => '5',
            'cf_'.$points->id => '20',
            'cf_'.$weight->id => '3.5',
        ], $runner->totals($other, $query));
        $this->assertNotContains($closed->id, $this->ids($runner->execute($world->user, $query)));
        $this->assertContains($child->id, $this->ids($runner->execute($world->user, $query)));

        $untotaled = $saved->create($world->user, [
            'name' => 'No totals',
            'project_id' => $world->project->id,
            'options' => ['display_type' => 'board'],
        ]);
        $this->assertSame([], $runner->totals($world->user, $untotaled));
        $this->assertSame(['display_type' => 'board'], $untotaled->options);

        $yamlId = DB::table('queries')->insertGetId([
            'name' => 'Legacy totals',
            'type' => QueryType::ISSUE,
            'user_id' => $world->user->id,
            'project_id' => $world->project->id,
            'visibility' => 0,
            'filters' => '{"status_id":{"operator":"o","values":[]}}',
            'options' => "---\n:totalable_names:\n- :estimated_hours\n- :spent_hours\n",
        ]);
        $legacy = IssueQuery::query()->findOrFail($yamlId);
        $this->assertSame([
            'estimated_hours' => '10.5',
            'spent_hours' => '5',
        ], $runner->totals($world->user, $legacy));
    }

    public function test_totals_reject_unknown_hidden_and_non_totalable_columns(): void
    {
        $world = $this->member();
        $saved = app(SavedQueryService::class);
        $runner = app(IssueQueryRunner::class);
        $bar = CustomField::query()->create([
            'name' => 'Progress',
            'field_format' => 'progressbar',
            'type' => 'IssueCustomField',
            'is_for_all' => true,
            'visible' => true,
        ]);
        $hidden = CustomField::query()->create([
            'name' => 'Hidden points',
            'field_format' => 'int',
            'type' => 'IssueCustomField',
            'is_for_all' => true,
            'visible' => false,
        ]);
        $projectField = CustomField::query()->create([
            'name' => 'Budget',
            'field_format' => 'int',
            'type' => 'ProjectCustomField',
            'is_for_all' => true,
            'visible' => true,
        ]);
        $issue = $this->issue($world, ['subject' => 'Counted', 'estimated_hours' => 4]);
        $this->custom($hidden, $issue, '8');

        $this->assertTotalRejected($world, ['subject'], 'Total column is not available: subject.');
        $this->assertTotalRejected($world, ['spent_time'], 'Total column is not available: spent_time.');
        $this->assertTotalRejected($world, ['estimated_hours', 'estimated_hours'], 'Total column is repeated: estimated_hours.');
        $this->assertTotalRejected($world, 'estimated_hours', 'Query totals must be a list.');
        $this->assertTotalRejected($world, ['cf_'.$bar->id], 'Custom field is not totalable: cf_'.$bar->id.'.');
        $this->assertTotalRejected($world, ['cf_'.$hidden->id], 'Custom field is not visible: cf_'.$hidden->id.'.');
        $this->assertTotalRejected($world, ['cf_'.$projectField->id], 'Custom field total is unknown: cf_'.$projectField->id.'.');
        $this->assertTotalRejected($world, ['cf_999999'], 'Custom field total is unknown: cf_999999.');
        $this->assertSame(0, DB::table('queries')->where('name', 'Bad totals')->count());

        $admin = User::factory()->create(['admin' => true]);
        $public = $saved->create($admin, [
            'name' => 'Hidden total',
            'project_id' => $world->project->id,
            'visibility' => 2,
            'options' => ['totalable_names' => ['cf_'.$hidden->id]],
        ]);
        $this->assertSame(['cf_'.$hidden->id => '8'], $runner->totals($admin, $public));

        try {
            $runner->totals($world->user, $public);
            $this->fail('A hidden custom field total must not be returned.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Custom field is not visible: cf_'.$hidden->id.'.', $exception->getMessage());
        }

        $private = $saved->create($world->user, [
            'name' => 'Private totals',
            'project_id' => $world->project->id,
            'visibility' => 0,
            'options' => ['totalable_names' => ['estimated_hours']],
        ]);
        $stranger = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $stranger, $world->role);

        try {
            $runner->totals($stranger, $private);
            $this->fail('A private query must not total for another user.');
        } catch (DomainException $exception) {
            $this->assertSame('Saved query is not visible.', $exception->getMessage());
        }
    }

    /**
     * @param  list<string>|string  $names
     */
    private function assertTotalRejected(DomainFixture $world, array|string $names, string $message): void
    {
        try {
            app(SavedQueryService::class)->create($world->user, [
                'name' => 'Bad totals',
                'project_id' => $world->project->id,
                'options' => ['totalable_names' => $names],
            ]);
            $this->fail($message);
        } catch (QueryValidationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function hours(DomainFixture $world, Enumeration $activity, Issue $issue, float $hours): void
    {
        TimeEntry::query()->create([
            'project_id' => $issue->project_id,
            'issue_id' => $issue->id,
            'user_id' => $world->user->id,
            'author_id' => $world->user->id,
            'activity_id' => $activity->id,
            'hours' => $hours,
            'spent_on' => '2026-09-29',
            'tmonth' => 9,
            'tweek' => 40,
            'tyear' => 2026,
        ]);
    }

    private function custom(CustomField $field, Issue $issue, string $value): void
    {
        CustomValue::query()->create([
            'custom_field_id' => $field->id,
            'customized_type' => 'Issue',
            'customized_id' => $issue->id,
            'value' => $value,
        ]);
    }

    private function member(): DomainFixture
    {
        $world = DomainFixture::boot('query-feature');
        $world->role->permissions = [
            'view_issues',
            'add_issues',
            'edit_issues',
            'save_queries',
        ];
        $world->role->save();
        $world->join();

        return $world;
    }

    private function tracker(DomainFixture $world, string $name): Tracker
    {
        $tracker = Tracker::query()->create([
            'name' => $name,
            'default_status_id' => $world->newStatus->id,
            'position' => 2,
        ]);
        app(ProjectService::class)->attachTracker($world->project, $tracker);

        return $tracker;
    }

    private function secondProject(DomainFixture $world): Project
    {
        $projects = app(ProjectService::class);
        $project = $projects->create([
            'name' => 'Other',
            'identifier' => 'other',
            'is_public' => false,
        ]);
        $projects->enableModule($project, 'issue_tracking');
        $projects->attachTracker($project, $world->tracker);
        app(MembershipService::class)->assignRole($project, $world->user, $world->role);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issue(DomainFixture $world, array $overrides): Issue
    {
        return Issue::query()->create([
            'project_id' => $world->project->id,
            'tracker_id' => $world->tracker->id,
            'status_id' => $world->newStatus->id,
            'priority_id' => $world->priority->id,
            'author_id' => $world->user->id,
            'subject' => 'Subject',
            'done_ratio' => 0,
            'is_private' => false,
            'lock_version' => 0,
            ...$overrides,
        ]);
    }

    /**
     * @param  Builder<Issue>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        $ids = [];
        foreach ($query->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
