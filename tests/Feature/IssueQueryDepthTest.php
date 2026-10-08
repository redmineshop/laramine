<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\DomainException;
use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryColumns;
use App\Domain\Queries\IssueQueryDisplay;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Queries\SavedQueryService;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class IssueQueryDepthTest extends TestCase
{
    use RefreshDatabase;

    public function test_present_projects_columns_and_display_type(): void
    {
        $world = $this->member();
        $hidden = CustomField::query()->create([
            'name' => 'Hidden label',
            'field_format' => 'string',
            'type' => 'IssueCustomField',
            'is_for_all' => true,
            'visible' => false,
        ]);
        $label = CustomField::query()->create([
            'name' => 'Label',
            'field_format' => 'string',
            'type' => 'IssueCustomField',
            'is_for_all' => true,
            'visible' => true,
        ]);
        $rank = CustomField::query()->create([
            'name' => 'Rank',
            'field_format' => 'enumeration',
            'type' => 'IssueCustomField',
            'is_for_all' => true,
            'visible' => true,
        ]);
        $early = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $rank->id,
            'name' => 'Early',
            'active' => true,
            'position' => 1,
        ]);
        $owner = User::factory()->create(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $category = IssueCategory::query()->create([
            'name' => 'Ops',
            'project_id' => $world->project->id,
        ]);
        $version = Version::query()->create([
            'name' => '1.0',
            'project_id' => $world->project->id,
            'status' => 'open',
            'sharing' => 'none',
        ]);
        $parent = $this->issue($world, ['subject' => 'Parent']);
        $issue = $this->issue($world, [
            'subject' => 'Card',
            'description' => 'Body',
            'assigned_to_id' => $owner->id,
            'category_id' => $category->id,
            'fixed_version_id' => $version->id,
            'parent_id' => $parent->id,
            'start_date' => '2026-10-03',
            'estimated_hours' => 2.5,
            'done_ratio' => 40,
            'is_private' => false,
            'status_id' => $world->inProgress->id,
        ]);
        DB::table('issues')->where('id', $issue->id)->update([
            'updated_on' => '2026-10-02 08:09:10',
            'created_on' => '2026-10-01 07:06:05',
        ]);
        $this->value($label, $issue, 'bee');
        $this->value($label, $issue, 'ant');
        $this->value($rank, $issue, (string) $early->id);
        $this->value($hidden, $issue, 'secret');

        $saved = app(SavedQueryService::class);
        $runner = app(IssueQueryRunner::class);
        $defaults = $saved->create($world->user, [
            'name' => 'Defaults',
            'project_id' => $world->project->id,
            'filters' => ['subject' => ['operator' => '=', 'values' => ['Card']]],
        ]);
        $defaultView = $runner->present($world->user, $defaults);
        $this->assertSame(IssueQueryDisplay::LIST, $defaultView->displayType);
        $this->assertSame(IssueQueryColumns::DEFAULT, $defaultView->columns);
        $this->assertSame([], $defaultView->board);
        $this->assertSame($issue->id, $defaultView->rows[0]->issueId);
        $this->assertSame([
            'tracker' => 'Bug',
            'status' => 'In Progress',
            'priority' => 'Normal',
            'subject' => 'Card',
            'assigned_to' => 'Ada Lovelace',
            'updated_on' => '2026-10-02 08:09:10',
        ], $defaultView->rows[0]->values);

        $selected = $saved->create($world->user, [
            'name' => 'Selected',
            'project_id' => $world->project->id,
            'filters' => ['subject' => ['operator' => '=', 'values' => ['Card']]],
            'column_names' => [
                'subject',
                'subject',
                'status',
                'total_spent_hours',
                'project',
                'author',
                'category',
                'fixed_version',
                'parent',
                'start_date',
                'estimated_hours',
                'done_ratio',
                'is_private',
                'description',
                'created_on',
                'cf_'.$label->id,
                'cf_'.$rank->id,
                'cf_'.$hidden->id,
                'cf_999999',
            ],
            'options' => ['display_type' => 'list'],
        ]);
        $this->assertContains('total_spent_hours', $selected->displayColumns());
        $view = $runner->present($world->user, $selected);
        $this->assertSame([
            'subject',
            'status',
            'project',
            'author',
            'category',
            'fixed_version',
            'parent',
            'start_date',
            'estimated_hours',
            'done_ratio',
            'is_private',
            'description',
            'created_on',
            'cf_'.$label->id,
            'cf_'.$rank->id,
        ], $view->columns);
        $this->assertSame(
            trim($world->user->firstname.' '.$world->user->lastname),
            $view->rows[0]->values['author'],
        );
        $this->assertSame('Demo', $view->rows[0]->values['project']);
        $this->assertSame('Ops', $view->rows[0]->values['category']);
        $this->assertSame('1.0', $view->rows[0]->values['fixed_version']);
        $this->assertSame((string) $parent->id, $view->rows[0]->values['parent']);
        $this->assertSame('2026-10-03', $view->rows[0]->values['start_date']);
        $this->assertSame('2.5', $view->rows[0]->values['estimated_hours']);
        $this->assertSame('40', $view->rows[0]->values['done_ratio']);
        $this->assertSame('0', $view->rows[0]->values['is_private']);
        $this->assertSame('Body', $view->rows[0]->values['description']);
        $this->assertSame('2026-10-01 07:06:05', $view->rows[0]->values['created_on']);
        $this->assertSame('bee, ant', $view->rows[0]->values['cf_'.$label->id]);
        $this->assertSame('Early', $view->rows[0]->values['cf_'.$rank->id]);
        $this->assertArrayNotHasKey('cf_'.$hidden->id, $view->rows[0]->values);
        $this->assertSame([], $view->board);

        $admin = User::factory()->create(['admin' => true]);
        $adminView = $runner->present($admin, $selected);
        $this->assertContains('cf_'.$hidden->id, $adminView->columns);
        $this->assertSame('secret', $adminView->rows[0]->values['cf_'.$hidden->id]);

        $blank = $saved->create($world->user, [
            'name' => 'No columns',
            'project_id' => $world->project->id,
            'filters' => ['subject' => ['operator' => '=', 'values' => ['Card']]],
            'column_names' => [],
        ]);
        $blankView = $runner->present($world->user, $blank);
        $this->assertSame([], $blankView->columns);
        $this->assertSame($issue->id, $blankView->rows[0]->issueId);
        $this->assertSame([], $blankView->rows[0]->values);

        $open = $this->issue($world, ['subject' => 'Open card', 'status_id' => $world->newStatus->id]);
        $later = $this->issue($world, ['subject' => 'Later card', 'status_id' => $world->inProgress->id]);
        try {
            $saved->create($world->user, [
                'name' => 'Board off',
                'project_id' => $world->project->id,
                'options' => ['display_type' => 'board'],
            ]);
            $this->fail('Issue board stays off unless the extension flag is set.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Query display type is not available: board.', $exception->getMessage());
        }

        Config::set('redmine.issue_query_board', true);
        $board = $saved->create($world->user, [
            'name' => 'Board',
            'project_id' => $world->project->id,
            'filters' => ['subject' => ['operator' => '=', 'values' => ['Open card', 'Later card', 'Card']]],
            'sort_criteria' => [['id', 'asc']],
            'group_by' => 'subject',
            'options' => ['display_type' => 'board'],
        ]);
        $boardView = $runner->present($world->user, $board);
        $this->assertSame(IssueQueryDisplay::BOARD, $boardView->displayType);
        $this->assertSame(
            [$world->newStatus->id, $world->inProgress->id],
            array_map(static fn ($column) => $column->statusId, $boardView->board),
        );
        $this->assertSame(['New', 'In Progress'], array_map(static fn ($column) => $column->name, $boardView->board));
        $this->assertSame([$open->id], $boardView->board[0]->issueIds);
        $this->assertSame([$issue->id, $later->id], $boardView->board[1]->issueIds);
        Config::set('redmine.issue_query_board', false);

        try {
            $saved->create($world->user, [
                'name' => 'Gantt',
                'project_id' => $world->project->id,
                'options' => ['display_type' => 'gantt'],
            ]);
            $this->fail('An unknown display type must not be saved.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Query display type is not available: gantt.', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('queries')->where('name', 'Gantt')->count());

        $stub = $saved->create($world->user, [
            'name' => 'Stub board',
            'type' => QueryType::PROJECT,
            'project_id' => $world->project->id,
            'options' => ['display_type' => 'gantt'],
        ]);
        $this->assertSame('gantt', $stub->options['display_type'] ?? null);

        $legacyId = DB::table('queries')->insertGetId([
            'name' => 'Legacy gantt',
            'type' => QueryType::ISSUE,
            'user_id' => $world->user->id,
            'project_id' => $world->project->id,
            'visibility' => 0,
            'filters' => '{}',
            'options' => '{"display_type":"gantt"}',
        ]);
        $legacy = Query::query()->findOrFail($legacyId);
        try {
            $runner->present($world->user, $legacy);
            $this->fail('An unknown display type must not be presented.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Query display type is not available: gantt.', $exception->getMessage());
        }
        try {
            $runner->totals($world->user, $legacy);
            $this->fail('An unknown display type must not be totaled.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Query display type is not available: gantt.', $exception->getMessage());
        }

        $private = $saved->create($world->user, [
            'name' => 'Private list',
            'project_id' => $world->project->id,
            'visibility' => 0,
        ]);
        $stranger = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $stranger, $world->role);
        try {
            $runner->present($stranger, $private);
            $this->fail('A private query must not be presented to another user.');
        } catch (DomainException $exception) {
            $this->assertSame('Saved query is not visible.', $exception->getMessage());
        }
    }

    public function test_spent_hours_follow_time_entries_visibility(): void
    {
        $world = $this->member();
        $ada = User::factory()->create(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $bea = User::factory()->create(['firstname' => 'Bea', 'lastname' => 'Coder']);
        $cy = User::factory()->create(['firstname' => 'Cy', 'lastname' => 'None']);
        $own = $this->role('time_own', [
            'view_issues',
            'view_time_entries',
            'save_queries',
        ], 'own');
        $wide = $this->role('time_all', [
            'view_issues',
            'view_time_entries',
            'save_queries',
        ], 'all');
        $decoy = $this->role('time_all_without_permission', [
            'view_issues',
            'save_queries',
        ], 'all');
        $blind = $this->role('time_blind', [
            'view_issues',
            'save_queries',
        ], 'all');
        $memberships = app(MembershipService::class);
        $memberships->assignRole($world->project, $ada, $own);
        $memberships->assignRole($world->project, $ada, $decoy);
        $memberships->assignRole($world->project, $bea, $wide);
        $memberships->assignRole($world->project, $cy, $blind);

        $activity = Enumeration::query()->create([
            'name' => 'Design',
            'type' => 'TimeEntryActivity',
            'active' => true,
            'position' => 1,
        ]);
        $issue = $this->issue($world, [
            'subject' => 'Timed',
            'author_id' => $bea->id,
        ]);
        $this->hours($world->project, $issue, $ada, $activity, 1.5);
        $this->hours($world->project, $issue, $bea, $activity, 2);

        $saved = app(SavedQueryService::class);
        $runner = app(IssueQueryRunner::class);
        $query = $saved->create($bea, [
            'name' => 'Hours',
            'project_id' => $world->project->id,
            'visibility' => 2,
            'filters' => ['subject' => ['operator' => '=', 'values' => ['Timed']]],
            'column_names' => ['subject', 'spent_hours'],
            'options' => ['totalable_names' => ['spent_hours']],
        ]);

        $this->assertSame(['spent_hours' => '3.5'], $runner->totals($bea, $query));
        $this->assertSame('3.5', $runner->present($bea, $query)->rows[0]->values['spent_hours']);
        $this->assertSame(['spent_hours' => '1.5'], $runner->totals($ada, $query));
        $this->assertSame('1.5', $runner->present($ada, $query)->rows[0]->values['spent_hours']);
        $this->assertSame(['spent_hours' => '0'], $runner->totals($cy, $query));
        $this->assertSame('0', $runner->present($cy, $query)->rows[0]->values['spent_hours']);

        $admin = User::factory()->create(['admin' => true]);
        $this->assertSame(['spent_hours' => '3.5'], $runner->totals($admin, $query));
        $this->assertSame('3.5', $runner->present($admin, $query)->rows[0]->values['spent_hours']);

        $this->assertSame(
            [],
            $this->ids($runner->preview($ada, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Timed']],
                'spent_time' => ['operator' => '>=', 'values' => ['3']],
            ])),
        );
        $this->assertSame(
            [$issue->id],
            $this->ids($runner->preview($ada, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Timed']],
                'spent_time' => ['operator' => '>=', 'values' => ['1']],
            ])),
        );
        $this->assertSame(
            [$issue->id],
            $this->ids($runner->preview($bea, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Timed']],
                'spent_time' => ['operator' => '>=', 'values' => ['3']],
            ])),
        );
        $this->assertSame(
            [],
            $this->ids($runner->preview($cy, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Timed']],
                'spent_time' => ['operator' => '*', 'values' => []],
            ])),
        );
        $this->assertSame(
            [$issue->id],
            $this->ids($runner->preview($admin, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Timed']],
                'spent_time' => ['operator' => '>=', 'values' => ['3']],
            ])),
        );

        $opening = $this->role('time_all_with_permission', [
            'view_issues',
            'view_time_entries',
        ], 'all');
        $memberships->assignRole($world->project, $ada, $opening);
        $this->assertSame(['spent_hours' => '3.5'], $runner->totals($ada, $query));
        $this->assertSame('3.5', $runner->present($ada, $query)->rows[0]->values['spent_hours']);
        $this->assertSame(
            [$issue->id],
            $this->ids($runner->preview($ada, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Timed']],
                'spent_time' => ['operator' => '>=', 'values' => ['3']],
            ])),
        );
    }

    public function test_spent_time_filter_follows_time_entries_visibility(): void
    {
        $world = $this->member();
        $ada = User::factory()->create(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $bea = User::factory()->create(['firstname' => 'Bea', 'lastname' => 'Coder']);
        $ned = User::factory()->create(['firstname' => 'Ned', 'lastname' => 'None']);
        $odd = User::factory()->create(['firstname' => 'Odd', 'lastname' => 'Value']);
        $own = $this->role('filter_own', ['view_issues', 'view_time_entries'], 'own');
        $wide = $this->role('filter_all', ['view_issues', 'view_time_entries'], 'all');
        $closed = $this->role('filter_none', ['view_issues', 'view_time_entries'], 'none');
        $unknown = $this->role('filter_unknown', ['view_issues', 'view_time_entries'], 'members');
        $memberships = app(MembershipService::class);
        $memberships->assignRole($world->project, $ada, $own);
        $memberships->assignRole($world->project, $bea, $wide);
        $memberships->assignRole($world->project, $ned, $closed);
        $memberships->assignRole($world->project, $odd, $unknown);

        $projects = app(ProjectService::class);
        $otherProject = $projects->create([
            'name' => 'Elsewhere',
            'identifier' => 'query-spent-elsewhere',
            'is_public' => true,
        ]);
        $projects->enableModule($otherProject, 'issue_tracking');
        $projects->attachTracker($otherProject, $world->tracker);
        $memberships->assignRole($otherProject, $ada, $wide);
        $memberships->assignRole($otherProject, $bea, $own);
        $memberships->assignRole($otherProject, $ned, $closed);
        $memberships->assignRole($otherProject, $odd, $unknown);

        $activity = Enumeration::query()->create([
            'name' => 'Design',
            'type' => 'TimeEntryActivity',
            'active' => true,
            'position' => 1,
        ]);
        $here = $this->issue($world, ['subject' => 'Here', 'author_id' => $ada->id]);
        $there = $this->issue($world, [
            'project_id' => $otherProject->id,
            'subject' => 'There',
            'author_id' => $ada->id,
        ]);
        $this->hours($world->project, $here, $ada, $activity, 1.5);
        $this->hours($world->project, $here, $bea, $activity, 2);
        $this->hours($otherProject, $there, $ada, $activity, 4);
        $this->hours($otherProject, $there, $bea, $activity, 1);
        TimeEntry::query()->create([
            'project_id' => $otherProject->id,
            'issue_id' => $here->id,
            'user_id' => $bea->id,
            'author_id' => $bea->id,
            'activity_id' => $activity->id,
            'hours' => 10,
            'spent_on' => '2026-10-01',
            'tmonth' => 10,
            'tweek' => 40,
            'tyear' => 2026,
        ]);

        $runner = app(IssueQueryRunner::class);
        $filters = [
            'subject' => ['operator' => '=', 'values' => ['Here', 'There']],
        ];

        $this->assertSame([$there->id], $this->ids($runner->preview($ada, null, [
            ...$filters,
            'spent_time' => ['operator' => '>=', 'values' => ['4']],
        ])));
        $this->assertSame([$here->id], $this->ids($runner->preview($ada, null, [
            ...$filters,
            'spent_time' => ['operator' => '=', 'values' => ['1.5']],
        ])));
        $this->assertSame([$here->id, $there->id], $this->ids($runner->preview($ada, null, [
            ...$filters,
            'spent_time' => ['operator' => '*', 'values' => []],
        ])));

        $this->assertSame([$here->id], $this->ids($runner->preview($bea, null, [
            ...$filters,
            'spent_time' => ['operator' => '>=', 'values' => ['3']],
        ])));
        $this->assertSame([$there->id], $this->ids($runner->preview($bea, null, [
            ...$filters,
            'spent_time' => ['operator' => '=', 'values' => ['1']],
        ])));
        $this->assertSame([], $this->ids($runner->preview($bea, null, [
            ...$filters,
            'spent_time' => ['operator' => '>=', 'values' => ['10']],
        ])));

        foreach ([$ned, $odd] as $hidden) {
            $this->assertSame([], $this->ids($runner->preview($hidden, null, [
                ...$filters,
                'spent_time' => ['operator' => '*', 'values' => []],
            ])));
            $this->assertSame([$here->id, $there->id], $this->ids($runner->preview($hidden, null, [
                ...$filters,
                'spent_time' => ['operator' => '!*', 'values' => []],
            ])));
            $this->assertSame([$here->id, $there->id], $this->ids($runner->preview($hidden, null, [
                ...$filters,
                'spent_time' => ['operator' => '=', 'values' => ['0']],
            ])));
        }

        $admin = User::factory()->create(['admin' => true]);
        $this->assertSame([$here->id, $there->id], $this->ids($runner->preview($admin, null, [
            ...$filters,
            'spent_time' => ['operator' => '><', 'values' => ['3', '6']],
        ])));
        $this->assertSame([], $this->ids($runner->preview($admin, null, [
            ...$filters,
            'spent_time' => ['operator' => '>=', 'values' => ['10']],
        ])));
    }

    public function test_spent_hours_use_each_issue_project(): void
    {
        $world = $this->member();
        $actor = User::factory()->create(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $other = User::factory()->create(['firstname' => 'Bea', 'lastname' => 'Coder']);
        $own = $this->role('project_own', [
            'view_issues',
            'view_time_entries',
            'save_queries',
        ], 'own');
        $wide = $this->role('project_all', [
            'view_issues',
            'view_time_entries',
            'save_queries',
        ], 'all');
        $projects = app(ProjectService::class);
        $otherProject = $projects->create([
            'name' => 'Other',
            'identifier' => 'query-hours-other',
            'is_public' => true,
        ]);
        $projects->enableModule($otherProject, 'issue_tracking');
        $projects->attachTracker($otherProject, $world->tracker);
        $memberships = app(MembershipService::class);
        $memberships->assignRole($world->project, $actor, $own);
        $memberships->assignRole($world->project, $other, $wide);
        $memberships->assignRole($otherProject, $actor, $wide);
        $memberships->assignRole($otherProject, $other, $wide);

        $activity = Enumeration::query()->create([
            'name' => 'Design',
            'type' => 'TimeEntryActivity',
            'active' => true,
            'position' => 1,
        ]);
        $here = $this->issue($world, ['subject' => 'Here', 'author_id' => $actor->id]);
        $there = $this->issue($world, [
            'project_id' => $otherProject->id,
            'subject' => 'There',
            'author_id' => $actor->id,
        ]);
        $this->hours($world->project, $here, $actor, $activity, 1);
        $this->hours($world->project, $here, $other, $activity, 4);
        $this->hours($otherProject, $there, $actor, $activity, 2);
        $this->hours($otherProject, $there, $other, $activity, 3);

        $query = app(SavedQueryService::class)->create($actor, [
            'name' => 'Global hours',
            'visibility' => 0,
            'filters' => ['subject' => ['operator' => '=', 'values' => ['Here', 'There']]],
            'column_names' => ['subject', 'spent_hours'],
            'sort_criteria' => [['subject', 'asc']],
            'options' => ['totalable_names' => ['spent_hours']],
        ]);
        $runner = app(IssueQueryRunner::class);
        $view = $runner->present($actor, $query);
        $this->assertSame(['Here', 'There'], [
            $view->rows[0]->values['subject'],
            $view->rows[1]->values['subject'],
        ]);
        $this->assertSame('1', $view->rows[0]->values['spent_hours']);
        $this->assertSame('5', $view->rows[1]->values['spent_hours']);
        $this->assertSame(['spent_hours' => '6'], $runner->totals($actor, $query));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function role(string $name, array $permissions, string $timeVisibility): Role
    {
        $role = Role::query()->create([
            'name' => $name,
            'builtin' => 0,
            'assignable' => true,
            'permissions' => $permissions,
            'issues_visibility' => 'all',
            'time_entries_visibility' => $timeVisibility,
        ]);
        $this->assertInstanceOf(Role::class, $role);

        return $role;
    }

    private function hours(Project $project, Issue $issue, User $user, Enumeration $activity, float $hours): void
    {
        TimeEntry::query()->create([
            'project_id' => $project->id,
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'author_id' => $user->id,
            'activity_id' => $activity->id,
            'hours' => $hours,
            'spent_on' => '2026-10-01',
            'tmonth' => 10,
            'tweek' => 40,
            'tyear' => 2026,
        ]);
    }

    private function value(CustomField $field, Issue $issue, string $value): void
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
        $world = DomainFixture::boot('query-depth');
        $world->role->permissions = [
            'view_issues',
            'add_issues',
            'edit_issues',
            'save_queries',
        ];
        $world->role->issues_visibility = 'all';
        $world->role->save();
        $world->join();

        return $world;
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
