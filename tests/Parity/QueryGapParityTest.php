<?php

namespace Tests\Parity;

use App\Domain\Calendar\CalendarService;
use App\Domain\DomainException;
use App\Domain\Gantt\GanttChart;
use App\Domain\Queries\IssueQueryGrouping;
use App\Domain\Queries\IssueQueryLayout;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\IssueQuerySelection;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryVisibility;
use App\Domain\Queries\SavedQueryService;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Project;
use App\Models\Query;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JsonException;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares the query gaps that sat outside the earlier pin: saved-query
 * calendar and gantt, group headers, association custom fields, and column layout.
 *
 * Issue-relation custom fields and a board axis other than status are N/A
 * with the 7.0.1 schema. The REST issue and project lists stay on their row.
 */
class QueryGapParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_queries_groups_associations_and_columns_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->seedAssociatedFields();
        $admin = $this->actor('admin');
        $ada = $this->actor('ada');
        $finn = $this->actor('finn');
        $project = $this->project(1);
        $runner = app(IssueQueryRunner::class);
        $calendar = app(CalendarService::class);
        $gantt = app(GanttChart::class);
        $selection = app(IssueQuerySelection::class);
        $layout = app(IssueQueryLayout::class);
        $grouping = app(IssueQueryGrouping::class);
        $today = new \DateTimeImmutable('2026-09-15');
        $window = ['year' => 2026, 'month' => 9, 'months' => 2, 'zoom' => 2];

        $actual = [
            'calendar' => [
                'query_6' => $this->eventDays($calendar->monthForQuery($admin, $project, 2026, 9, ['query_id' => '6'], $today)),
                'query_3' => $this->eventDays($calendar->monthForQuery($admin, $project, 2026, 9, ['query_id' => '3'], $today)),
                'tracker_bug' => $this->eventDays($calendar->monthForQuery($admin, $project, 2026, 9, [
                    'set_filter' => '1',
                    'filters' => [
                        'status_id' => ['operator' => 'o', 'values' => []],
                        'tracker_id' => ['operator' => '=', 'values' => ['1']],
                    ],
                    'sort' => [['id', 'asc']],
                ], $today)),
                'group_ignored' => $this->eventDays($calendar->monthForQuery($admin, $project, 2026, 9, [
                    'set_filter' => '1',
                    'filters' => ['status_id' => ['operator' => 'o', 'values' => []]],
                    'sort' => [['id', 'desc']],
                    'group_by' => 'tracker',
                ], $today)),
            ],
            'gantt' => [
                'query_6' => $this->ganttIssues($gantt->showForQuery($admin, $project, $window, ['query_id' => '6'], null, $today)),
                'query_3' => $this->ganttIssues($gantt->showForQuery($admin, $project, $window, ['query_id' => '3'], null, $today)),
                'tracker_bug' => $this->ganttIssues($gantt->showForQuery($admin, $project, $window, [
                    'set_filter' => '1',
                    'filters' => [
                        'status_id' => ['operator' => 'o', 'values' => []],
                        'tracker_id' => ['operator' => '=', 'values' => ['1']],
                    ],
                ], null, $today)),
            ],
            'groups' => [
                'tracker' => $this->grouped($runner, $admin, 'tracker', true),
                'status' => $this->grouped($runner, $admin, 'status', true),
                'priority' => $this->grouped($runner, $admin, 'priority', false),
                'author' => $this->grouped($runner, $admin, 'author', false),
                'assigned_to' => $this->grouped($runner, $admin, 'assigned_to', false),
                'category' => $this->grouped($runner, $admin, 'category', false),
                'fixed_version' => $this->grouped($runner, $admin, 'fixed_version', false),
                'project' => $this->grouped($runner, $admin, 'project', false),
                'done_ratio' => $this->grouped($runner, $admin, 'done_ratio', true),
                'start_date' => $this->grouped($runner, $admin, 'start_date', false),
                'due_date' => $this->grouped($runner, $admin, 'due_date', false),
                'created_on' => $this->grouped($runner, $admin, 'created_on', false),
                'updated_on' => $this->grouped($runner, $admin, 'updated_on', false),
                'closed_on' => $this->grouped($runner, $admin, 'closed_on', false),
                'is_private' => $this->grouped($runner, $admin, 'is_private', false),
                'list' => $this->grouped($runner, $admin, 'cf_32', true),
                'bool' => $this->grouped($runner, $admin, 'cf_33', false),
                'date' => $this->grouped($runner, $admin, 'cf_35', false),
                'user' => $this->grouped($runner, $admin, 'cf_28', false),
                'version' => $this->grouped($runner, $admin, 'cf_30', false),
                'enumeration' => $this->grouped($runner, $admin, 'cf_36', false),
                'multiple' => $this->message(fn () => $grouping->assertGroupable('cf_31', $admin, null)),
                'ada_private' => $this->message(fn () => $grouping->assertGroupable('is_private', $ada, $project)),
                'subject_sort_only' => $this->grouped($runner, $admin, 'subject', false),
            ],
            'board' => $this->board($runner, $admin),
            'filters' => [
                'project_core' => $this->ids($runner, $admin, ['project.cf_22' => ['operator' => '=', 'values' => ['core']]]),
                'project_child' => $this->ids($runner, $admin, ['project.cf_22' => ['operator' => '=', 'values' => ['child']]]),
                'project_not_core' => $this->ids($runner, $admin, ['project.cf_22' => ['operator' => '!', 'values' => ['core']]]),
                'project_blank' => $this->ids($runner, $admin, ['project.cf_22' => ['operator' => '!*', 'values' => []]]),
                'project_budget' => $this->ids($runner, $admin, ['project.cf_23' => ['operator' => '>=', 'values' => ['10']]]),
                'project_lane' => $this->ids($runner, $admin, ['project.cf_26' => ['operator' => '=', 'values' => ['alpha']]]),
                'project_lane_ever' => $this->ids($runner, $admin, ['project.cf_26' => ['operator' => 'ev', 'values' => ['alpha']]]),
                'project_seats' => $this->message(fn () => $this->ids($runner, $admin, ['project.cf_25' => ['operator' => '=', 'values' => ['4']]])),
                'project_secret_finn' => $this->message(fn () => $this->ids($runner, $finn, ['project.cf_24' => ['operator' => '=', 'values' => ['hidden-core']]])),
                'author_lead' => $this->ids($runner, $admin, ['author.cf_27' => ['operator' => '=', 'values' => ['lead']]]),
                'author_not_lead' => $this->ids($runner, $admin, ['author.cf_27' => ['operator' => '!', 'values' => ['lead']]]),
                'assignee_crew' => $this->ids($runner, $admin, ['assigned_to.cf_27' => ['operator' => '=', 'values' => ['crew']]]),
                'assignee_blank' => $this->ids($runner, $admin, ['assigned_to.cf_27' => ['operator' => '!*', 'values' => []]]),
                'version_gold' => $this->ids($runner, $admin, ['fixed_version.cf_29' => ['operator' => '=', 'values' => ['gold']]]),
                'user_chain' => $this->ids($runner, $admin, ['cf_28.cf_27' => ['operator' => '=', 'values' => ['lead']]]),
                'version_chain' => $this->ids($runner, $admin, ['cf_30.cf_29' => ['operator' => '=', 'values' => ['gold']]]),
                'bad_suffix' => $this->message(fn () => $this->ids($runner, $admin, ['cf_28.due_date' => ['operator' => '=', 'values' => ['2026-09-01']]])),
            ],
            'columns' => $this->columns($runner, $layout, $admin, $ada, $project),
            'selection' => $this->selection($selection, $admin, $ada, $finn, $project),
            'sort' => $this->ids($runner, $admin, ['status_id' => ['operator' => '*', 'values' => []]], [['priority', 'desc'], ['id', 'asc']]),
            'na' => [
                'relation_custom_fields' => 'docs/sources/redmine-7.0.1-schema.rb lines 258-266',
                'board_axis' => 'issue_statuses',
            ],
        ];

        $schema = file_get_contents(base_path('docs/sources/redmine-7.0.1-schema.rb'));
        $this->assertIsString($schema);
        $this->assertStringContainsString('create_table "issue_relations"', $schema);
        $this->assertStringNotContainsString('custom_field', substr($schema, (int) strpos($schema, 'create_table "issue_relations"'), 700));

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/gaps.json', $actual);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Queries \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/QueryGapParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/gaps.json', $checklist);
        $this->assertStringContainsString('tests/Parity/IssueQueryParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/remainder.json', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — full REST API \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — OpenID Connect \| N\/A \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Calendar and Gantt \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — live LDAP \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki and boards visual UX \| NOT VERIFIED \|/m', $checklist);
    }

    private function seedAssociatedFields(): void
    {
        CustomField::unguarded(function (): void {
            $this->field(27, 'UserCustomField', 'string', 'Desk', false);
            $this->field(28, 'IssueCustomField', 'user', 'Owner link', false);
            $this->field(29, 'VersionCustomField', 'string', 'Metal', false);
            $this->field(30, 'IssueCustomField', 'version', 'Target link', false);
            $this->field(31, 'IssueCustomField', 'list', 'Tags', true);
            $this->field(32, 'IssueCustomField', 'list', 'Lane', false);
            $this->field(33, 'IssueCustomField', 'bool', 'Flag', false);
            $this->field(34, 'IssueCustomField', 'text', 'Body', false);
            $this->field(35, 'IssueCustomField', 'date', 'When', false);
            $this->field(36, 'IssueCustomField', 'enumeration', 'Rank', false);
        });
        CustomFieldEnumeration::unguarded(function (): void {
            CustomFieldEnumeration::query()->create([
                'id' => 100,
                'custom_field_id' => 36,
                'name' => 'Gold',
                'active' => true,
                'position' => 1,
            ]);
        });

        $this->value(27, 'User', 1, 'lead');
        $this->value(27, 'Group', 3, 'crew');
        $this->value(28, 'Issue', 1, '1');
        $this->value(29, 'Version', 1, 'gold');
        $this->value(30, 'Issue', 1, '1');
        $this->value(30, 'Issue', 6, '1');
        $this->value(32, 'Issue', 1, 'alpha');
        $this->value(32, 'Issue', 1, 'beta');
        $this->value(33, 'Issue', 1, '1');
        $this->value(33, 'Issue', 2, '0');
        $this->value(34, 'Issue', 1, 'long body');
        $this->value(35, 'Issue', 4, '2026-09-01');
        $this->value(36, 'Issue', 3, '100');
    }

    private function field(int $id, string $type, string $format, string $name, bool $multiple): void
    {
        CustomField::query()->create([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'field_format' => $format,
            'possible_values' => $format === 'list' ? ['alpha', 'beta'] : null,
            'regexp' => '',
            'is_required' => false,
            'is_for_all' => true,
            'is_filter' => $format !== 'text',
            'searchable' => false,
            'editable' => true,
            'visible' => true,
            'multiple' => $multiple,
            'position' => $id,
        ]);
    }

    private function value(int $fieldId, string $type, int $id, string $value): void
    {
        CustomValue::query()->create([
            'custom_field_id' => $fieldId,
            'customized_type' => $type,
            'customized_id' => $id,
            'value' => $value,
        ]);
    }

    /**
     * @return list<array{key: string|null, value: string|null, count: int, ids: list<int>, totals: array<string, string>}>
     */
    private function grouped(IssueQueryRunner $runner, User $actor, string $groupBy, bool $totals): array
    {
        $query = new Query;
        $query->type = QueryType::ISSUE;
        $query->name = 'Grouped';
        $query->user_id = $actor->id;
        $query->project_id = null;
        $query->visibility = QueryVisibility::PUBLIC;
        $query->filters = ['status_id' => ['operator' => '*', 'values' => []]];
        $query->column_names = ['id'];
        $query->sort_criteria = [['id', 'asc']];
        $query->group_by = $groupBy;
        $query->options = [
            'display_type' => 'list',
            'totalable_names' => $totals ? ['estimated_hours'] : [],
        ];

        $rows = [];
        foreach ($runner->present($actor, $query)->groups as $group) {
            $rows[] = [
                'key' => $group['key'],
                'value' => $group['value'],
                'count' => $group['count'],
                'ids' => $group['ids'],
                'totals' => $group['totals'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{display_type: string, board: list<array{status_id: int, name: string, issue_ids: list<int>}>, groups: list<array{key: string|null, value: string|null, ids: list<int>}>}
     */
    private function board(IssueQueryRunner $runner, User $actor): array
    {
        $view = $runner->present($actor, $this->saved(6));
        $board = [];
        foreach ($view->board as $column) {
            $board[] = [
                'status_id' => $column->statusId,
                'name' => $column->name,
                'issue_ids' => $column->issueIds,
            ];
        }
        $groups = [];
        foreach ($view->groups as $group) {
            $groups[] = [
                'key' => $group['key'],
                'value' => $group['value'],
                'ids' => $group['ids'],
            ];
        }

        return [
            'display_type' => $view->displayType,
            'board' => $board,
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<array{0: string, 1: string}>  $sort
     * @return list<int>
     */
    private function ids(IssueQueryRunner $runner, User $actor, array $filters, array $sort = [['id', 'asc']]): array
    {
        if (! isset($filters['status_id'])) {
            $filters = ['status_id' => ['operator' => '*', 'values' => []], ...$filters];
        }
        $ids = [];
        foreach ($runner->preview($actor, null, $filters, $sort)->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(IssueQueryRunner $runner, IssueQueryLayout $layout, User $admin, User $ada, Project $project): array
    {
        $query = new Query;
        $query->type = QueryType::ISSUE;
        $query->name = 'Layout';
        $query->user_id = $admin->id;
        $query->project_id = null;
        $query->visibility = QueryVisibility::PUBLIC;
        $query->filters = ['issue_id' => ['operator' => '=', 'values' => ['1']]];
        $query->column_names = ['subject', 'description', 'last_notes', 'cf_34', 'parent.subject', 'relations', 'attachments', 'watcher_users'];
        $query->sort_criteria = [['id', 'asc']];
        $query->group_by = null;
        $query->options = ['display_type' => 'list', 'totalable_names' => []];
        $view = $runner->present($admin, $query);

        $saved = app(SavedQueryService::class)->create($admin, [
            'name' => 'Column order',
            'project_id' => $project->id,
            'filters' => ['issue_id' => ['operator' => '=', 'values' => ['1']]],
            'column_names' => ['due_date', 'subject', 'id'],
            'sort_criteria' => [['due_date', 'desc'], ['id', 'asc']],
        ]);

        $flags = [];
        foreach ($layout->available($admin, null) as $row) {
            if (in_array($row['name'], ['description', 'last_notes', 'subject', 'tracker', 'estimated_hours', 'cf_32', 'cf_31', 'cf_34', 'is_private'], true)) {
                $flags[$row['name']] = [
                    'inline' => $row['inline'],
                    'groupable' => $row['groupable'],
                    'totalable' => $row['totalable'],
                    'frozen' => $row['frozen'],
                ];
            }
        }

        return [
            'inline' => $view->inlineColumns,
            'block' => $view->blockColumns,
            'cells' => $view->rows[0]->values,
            'global_default' => $layout->defaultNames(null),
            'project_default' => $layout->defaultNames($project),
            'after_setting' => $this->afterSetting($layout, $project),
            'saved_order' => $saved->fresh()?->column_names,
            'presented_order' => $runner->present($admin, $saved)->columns,
            'flags' => $flags,
            'ada_private' => $this->hasColumn($layout, $ada, 'is_private'),
            'admin_private' => $this->hasColumn($layout, $admin, 'is_private'),
        ];
    }

    /**
     * @return array{global: list<string>, project: list<string>}
     */
    private function afterSetting(IssueQueryLayout $layout, Project $project): array
    {
        Setting::query()->create([
            'name' => 'issue_list_default_columns',
            'value' => '["subject","id"]',
        ]);

        return [
            'global' => $layout->defaultNames(null),
            'project' => $layout->defaultNames($project),
        ];
    }

    private function hasColumn(IssueQueryLayout $layout, User $actor, string $name): bool
    {
        foreach ($layout->available($actor, null) as $row) {
            if ($row['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function selection(IssueQuerySelection $selection, User $admin, User $ada, User $finn, Project $project): array
    {
        $plain = $this->slice($selection->select($admin, $project, [], null));
        UserPreference::query()->where('user_id', $admin->id)->update([
            'others' => '{"default_issue_query":"3"}',
        ]);
        $preferred = $this->slice($selection->select($admin, $project, [], null));
        $skipped = $this->slice($selection->select($admin, $project, ['without_default' => '1'], null));
        UserPreference::query()->where('user_id', $admin->id)->update(['others' => null]);
        $project->default_issue_query_id = 6;
        $project->save();
        $projectDefault = $this->slice($selection->select($admin, $project->fresh(), [], null));
        $project->default_issue_query_id = null;
        $project->save();
        Setting::query()->create(['name' => 'default_issue_query', 'value' => '1']);
        $privateSetting = $this->slice($selection->select($admin, $project->fresh(), [], null));
        Setting::query()->where('name', 'default_issue_query')->update(['value' => '3']);
        $publicSetting = $this->slice($selection->select($admin, $project->fresh(), [], null));
        $session = $this->slice($selection->select($admin, $project->fresh(), [], [
            'project_id' => 1,
            'filters' => ['status_id' => ['operator' => '=', 'values' => ['1']]],
            'group_by' => 'tracker',
            'column_names' => ['subject', 'id'],
            'sort' => [['id', 'asc']],
            'totalable_names' => ['estimated_hours'],
        ]));
        $otherProject = $this->slice($selection->select($admin, $project->fresh(), [], [
            'project_id' => 2,
            'filters' => ['status_id' => ['operator' => '=', 'values' => ['4']]],
            'sort' => [['id', 'asc']],
        ]));

        return [
            'plain' => $plain,
            'preference' => $preferred,
            'without_default' => $skipped,
            'project_default' => $projectDefault,
            'private_setting' => $privateSetting,
            'public_setting' => $publicSetting,
            'session' => $session,
            'other_project' => $otherProject,
            'owner' => $this->slice($selection->select($ada, $project->fresh(), ['query_id' => '1'], null, false, false)),
            'hidden' => $this->message(fn () => $selection->select($finn, $project->fresh(), ['query_id' => '1'], null, false, false)),
        ];
    }

    /**
     * @param  array<string, mixed>  $selected
     * @return array<string, mixed>
     */
    private function slice(array $selected): array
    {
        return [
            'source' => $selected['source'],
            'query_id' => $selected['query_id'],
            'filters' => $selected['filters'],
            'column_names' => $selected['column_names'],
            'sort' => $selected['sort'],
            'group_by' => $selected['group_by'],
            'totalable_names' => $selected['totalable_names'],
        ];
    }

    /**
     * @param  array{days: list<array{date: string, events: list<array{kind: string, id: int}>}>}  $month
     * @return list<array{date: string, events: list<array{kind: string, id: int}>}>
     */
    private function eventDays(array $month): array
    {
        $days = [];
        foreach ($month['days'] as $day) {
            if ($day['events'] === []) {
                continue;
            }
            $days[] = [
                'date' => $day['date'],
                'events' => $day['events'],
            ];
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $chart
     * @return list<int>
     */
    private function ganttIssues(array $chart): array
    {
        $ids = [];
        $rows = $chart['rows'] ?? null;
        if (! is_array($rows)) {
            return [];
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['kind'] ?? null) !== 'issue' || ! is_int($row['id'] ?? null)) {
                continue;
            }
            $ids[] = $row['id'];
        }

        return $ids;
    }

    private function saved(int $id): Query
    {
        $query = Query::query()->find($id);
        $this->assertInstanceOf(Query::class, $query);

        return $query;
    }

    private function project(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function actor(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user, $login);

        return $user;
    }

    private function message(callable $callback): string
    {
        try {
            $callback();
        } catch (DomainException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected a domain exception.');

        return '';
    }

    private function assertPinned(string $path, mixed $actual): void
    {
        $full = base_path($path);
        try {
            $encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage());
        }

        if (getenv('LARAMINE_RECORD') === '1') {
            file_put_contents($full, $encoded);
        }

        $stored = file_get_contents($full);
        $this->assertIsString($stored);
        try {
            $expected = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage());
        }

        $this->assertSame($expected, $actual);
    }
}
