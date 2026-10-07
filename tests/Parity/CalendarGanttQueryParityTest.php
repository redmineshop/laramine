<?php

namespace Tests\Parity;

use App\Domain\Calendar\CalendarService;
use App\Domain\Gantt\GanttChart;
use App\Domain\PermissionDeniedException;
use App\Domain\Queries\IssueQueryProjection;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\ProjectQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Models\EnabledModule;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Version;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JsonException;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares the calendar, the gantt data layer, project queries, and descendant
 * hour columns to the shared pin.
 */
class CalendarGanttQueryParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $calendar = app(CalendarService::class);
        $admin = $this->actor('admin');
        $ada = $this->actor('ada');
        $today = new DateTimeImmutable('2026-10-07');

        $denied = null;
        try {
            $calendar->month($ada, null, 2026, 9, null, $today);
        } catch (PermissionDeniedException $exception) {
            $denied = $exception->getMessage();
        }

        $sundayCross = $this->events($calendar->month($admin, null, 2026, 9, null, $today));
        $sundayProject = $this->events($calendar->month($admin, $this->project(1), 2026, 9, null, $today));

        $this->grantChart();
        $adaCross = $this->events($calendar->month($ada, null, 2026, 9, null, $today));

        Setting::query()->create(['name' => 'start_of_week', 'value' => '1']);
        Setting::query()->create(['name' => 'non_working_week_days', 'value' => '["6","7"]']);

        $actual = [
            'ada_denied_before_grant' => $denied,
            'admin_cross_project' => $sundayCross,
            'admin_project' => $sundayProject,
            'ada_cross_project' => $adaCross,
            'monday_window' => $this->window($calendar->month($admin, null, 2026, 9, null, $today)),
            'version_day' => $this->versionDay($calendar, $admin, $today),
        ];

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/calendar/month.json', $actual);
    }

    public function test_gantt_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $gantt = app(GanttChart::class);
        $admin = $this->actor('admin');
        $ada = $this->actor('ada');
        $today = new DateTimeImmutable('2026-10-07');
        $window = ['year' => 2026, 'month' => 8, 'months' => 2, 'zoom' => 2];

        $denied = null;
        try {
            $gantt->show($ada, null, $window, null, null, $today);
        } catch (PermissionDeniedException $exception) {
            $denied = $exception->getMessage();
        }

        $base = $gantt->show($admin, null, $window, null, null, $today);
        $hidden = $gantt->show($admin, null, $window, null, ['draw_relations' => '0'], $today);
        $short = $gantt->show($admin, null, ['year' => 2026, 'month' => 8, 'months' => 2, 'zoom' => 2, 'max_rows' => 2], null, null, $today);

        $this->grantChart();
        $member = $gantt->show($ada, $this->project(1), $window, null, null, $today);

        $version = Version::query()->findOrFail(8);
        $version->status = 'closed';
        $version->effective_date = '2026-09-20';
        $version->save();
        Issue::query()->whereKey(6)->update(['fixed_version_id' => 8]);
        IssueRelation::query()->create([
            'issue_from_id' => 1,
            'issue_to_id' => 6,
            'relation_type' => 'precedes',
            'delay' => null,
        ]);
        $closed = $gantt->show($admin, null, ['year' => 2026, 'month' => 9, 'months' => 1, 'zoom' => 4], null, null, $today);

        $stored = UserPreference::query()->where('user_id', $admin->id)->value('others');

        $actual = [
            'ada_denied_before_grant' => $denied,
            'png' => 'n/a',
            'admin' => $this->chart($base),
            'relations_hidden' => $this->chart($hidden)['relations'],
            'truncated' => [
                'truncated' => $short['truncated'],
                'rows' => $this->chart($short)['rows'],
            ],
            'ada_project' => $this->chart($member),
            'closed_version' => $this->chart($closed),
            'preference' => is_string($stored) ? $stored : null,
        ];

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/gantt/chart.json', $actual);
    }

    public function test_project_queries_and_tree_hours_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $projects = app(ProjectQueryRunner::class);
        $issues = app(IssueQueryRunner::class);
        $projection = app(IssueQueryProjection::class);
        $admin = $this->actor('admin');
        $ada = $this->actor('ada');
        $finn = $this->actor('finn');
        $active = ['status' => ['operator' => '=', 'values' => ['1']]];

        $adminDenied = null;
        try {
            $projects->run($ada, QueryType::PROJECT_ADMIN, $active);
        } catch (PermissionDeniedException $exception) {
            $adminDenied = $exception->getMessage();
        }

        $columns = ['name', 'identifier', 'status', 'is_public', 'parent_id', 'last_activity_date'];
        $activeRows = $projects->run($admin, QueryType::PROJECT, $active, $columns, [['name', 'asc']]);
        $grouped = $projects->run($ada, QueryType::PROJECT, $active, $columns, [], 'is_public');
        $hours = [
            'admin' => $this->hours($issues, $projection, $admin),
            'finn' => $this->hours($issues, $projection, $finn),
            'ada_columns' => $projection->names($ada, null, ['total_spent_hours', 'total_estimated_hours']),
            'erin_columns' => $projection->names($this->actor('erin'), null, ['total_spent_hours', 'total_estimated_hours']),
        ];

        $child = Project::query()->findOrFail(2);
        $child->status = Project::STATUS_ARCHIVED;
        $child->save();

        $rejected = null;
        try {
            $projects->run($admin, QueryType::PROJECT, [
                'status' => ['operator' => '=', 'values' => ['9']],
            ]);
        } catch (QueryValidationException $exception) {
            $rejected = $exception->getMessage();
        }

        $actual = [
            'ada_admin_query_denied' => $adminDenied,
            'active' => $activeRows,
            'grouped' => $grouped,
            'archived_project_query_ids' => $projects->run($admin, QueryType::PROJECT, $active)['ids'],
            'archived_status_rejected' => $rejected,
            'archived_admin' => $projects->run($admin, QueryType::PROJECT_ADMIN, [
                'status' => ['operator' => '=', 'values' => ['9']],
            ], $columns)['ids'],
            'hours' => $hours,
        ];

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/projects-and-hours.json', $actual);
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return array<string, mixed>
     */
    private function events(array $grid): array
    {
        $days = [];
        $raw = $grid['days'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $day) {
                if (! is_array($day)) {
                    continue;
                }
                $events = $day['events'] ?? null;
                if (! is_array($events) || $events === []) {
                    continue;
                }
                $days[] = $day;
            }
        }

        return [
            'year' => $grid['year'] ?? null,
            'month' => $grid['month'] ?? null,
            'start' => $grid['start'] ?? null,
            'end' => $grid['end'] ?? null,
            'first_wday' => $grid['first_wday'] ?? null,
            'event_days' => $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return array<string, mixed>
     */
    private function window(array $grid): array
    {
        return [
            'start' => $grid['start'] ?? null,
            'end' => $grid['end'] ?? null,
            'first_wday' => $grid['first_wday'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionDay(CalendarService $calendar, User $admin, DateTimeImmutable $today): array
    {
        $version = Version::query()->findOrFail(1);
        $version->effective_date = '2026-09-15';
        $version->save();
        $grid = $calendar->month($admin, $this->project(1), 2026, 9, null, $today);
        $days = $this->events($grid)['event_days'] ?? null;

        $hit = [];
        if (is_array($days)) {
            foreach ($days as $day) {
                if (is_array($day) && ($day['date'] ?? null) === '2026-09-15') {
                    $hit = $day;
                }
            }
        }

        return $hit;
    }

    /**
     * @param  array<string, mixed>  $chart
     * @return array<string, mixed>
     */
    private function chart(array $chart): array
    {
        $pdf = $chart['pdf'] ?? '';
        $labels = [];
        $rows = $chart['rows'] ?? null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['name'] ?? null)) {
                    $labels[] = $row['name'];
                }
            }
        }
        $missing = [];
        if (is_string($pdf)) {
            foreach ($labels as $label) {
                if (! str_contains($pdf, $label)) {
                    $missing[] = $label;
                }
            }
        }

        unset($chart['pdf']);
        $chart['pdf_header'] = is_string($pdf) ? substr($pdf, 0, 8) : '';
        $chart['pdf_missing_labels'] = $missing;

        return $chart;
    }

    /**
     * @return list<array{id: int, total_estimated_hours: string|null, total_spent_hours: string|null}>
     */
    private function hours(IssueQueryRunner $issues, IssueQueryProjection $projection, User $actor): array
    {
        $loaded = $issues->preview($actor, null, [
            'status_id' => ['operator' => '*', 'values' => []],
        ], [['total_estimated_hours', 'desc'], ['id', 'asc']])->get();
        $columns = $projection->names($actor, null, ['total_estimated_hours', 'total_spent_hours']);
        $rows = [];
        foreach ($projection->rows($actor, $loaded, $columns) as $row) {
            $values = $row->values;
            $rows[] = [
                'id' => $row->issueId,
                'total_estimated_hours' => $values['total_estimated_hours'] ?? null,
                'total_spent_hours' => $values['total_spent_hours'] ?? null,
            ];
        }

        return $rows;
    }

    private function grantChart(): void
    {
        $role = Role::query()->findOrFail(1);
        $permissions = $role->permissions;
        $names = is_array($permissions) ? $permissions : [];
        $names[] = 'view_calendar';
        $names[] = 'view_gantt';
        $role->permissions = array_values(array_unique($names));
        $role->save();

        foreach ([1, 2] as $projectId) {
            foreach (['calendar', 'gantt'] as $name) {
                EnabledModule::query()->create([
                    'project_id' => $projectId,
                    'name' => $name,
                ]);
            }
        }
    }

    private function actor(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function project(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
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
            $directory = dirname($full);
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
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
