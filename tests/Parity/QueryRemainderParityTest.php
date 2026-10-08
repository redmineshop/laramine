<?php

namespace Tests\Parity;

use App\Domain\DomainException;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\ProjectQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryVisibility;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Document;
use App\Models\EnabledModule;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Message;
use App\Models\News;
use App\Models\Query;
use App\Models\Role;
use App\Models\User;
use App\Models\Wiki;
use App\Models\WikiContent;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JsonException;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares the query leftovers left outside the calendar and gantt pin:
 * remaining hours, project custom fields, and last-activity sources.
 *
 * Changeset activity stays N/A with the pin's repository excludes.
 */
class QueryRemainderParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_hours_project_fields_and_activity_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $issues = app(IssueQueryRunner::class);
        $projects = app(ProjectQueryRunner::class);
        $admin = $this->actor('admin');
        $ada = $this->actor('ada');
        $bea = $this->actor('bea');
        $finn = $this->actor('finn');
        $active = ['status' => ['operator' => '=', 'values' => ['1']]];
        $fieldColumns = ['name', 'cf_22', 'cf_23', 'cf_24', 'cf_25', 'cf_26'];

        $actual = [
            'remaining' => [
                'admin' => $this->remaining($issues, $admin),
                'finn' => $this->remaining($issues, $finn),
            ],
            'project_fields' => [
                'admin' => $this->projectSlice($projects, $admin, QueryType::PROJECT, $active, $fieldColumns),
                'ada' => $this->projectSlice($projects, $ada, QueryType::PROJECT, $active, $fieldColumns),
                'bea' => $this->projectSlice($projects, $bea, QueryType::PROJECT, $active, $fieldColumns),
                'finn' => $this->projectSlice($projects, $finn, QueryType::PROJECT, $active, $fieldColumns),
                'admin_query' => $this->projectSlice($projects, $admin, QueryType::PROJECT_ADMIN, $active, ['name']),
                'admin_denied' => $this->message(fn () => $projects->run($ada, QueryType::PROJECT_ADMIN, $active)),
                'label_core' => $this->ids($projects, $admin, ['cf_22' => ['operator' => '=', 'values' => ['core']]]),
                'budget_at_least_10' => $this->ids($projects, $admin, ['cf_23' => ['operator' => '>=', 'values' => ['10']]]),
                'lane_alpha' => $this->ids($projects, $admin, ['cf_26' => ['operator' => '=', 'values' => ['alpha']]]),
                'secret_child_admin' => $this->ids($projects, $admin, ['cf_24' => ['operator' => '=', 'values' => ['hidden-child']]]),
                'secret_core_ada' => $this->ids($projects, $ada, ['cf_24' => ['operator' => '=', 'values' => ['hidden-core']]]),
                'secret_child_ada' => $this->ids($projects, $ada, ['cf_24' => ['operator' => '=', 'values' => ['hidden-child']]]),
                'secret_blank_ada' => $this->ids($projects, $ada, ['cf_24' => ['operator' => '!*', 'values' => []]]),
                'secret_core_bea' => $this->ids($projects, $bea, ['cf_24' => ['operator' => '=', 'values' => ['hidden-core']]]),
                'finn_secret' => $this->message(fn () => $projects->run($finn, QueryType::PROJECT, ['cf_24' => ['operator' => '=', 'values' => ['hidden-core']]])),
                'seats_not_filter' => $this->message(fn () => $projects->run($admin, QueryType::PROJECT, ['cf_25' => ['operator' => '=', 'values' => ['4']]])),
                'archived_status' => $this->message(fn () => $projects->run($ada, QueryType::PROJECT, ['status' => ['operator' => '=', 'values' => ['9']]])),
                'admin_archived' => $this->ids($projects, $admin, ['status' => ['operator' => '=', 'values' => ['9']]], QueryType::PROJECT_ADMIN),
                'budget_desc' => $this->ids($projects, $admin, $active, QueryType::PROJECT, [['cf_23', 'desc']]),
                'label_asc' => $this->ids($projects, $admin, $active, QueryType::PROJECT, [['cf_22', 'asc']]),
                'totals' => [
                    'admin' => $projects->totals($admin, QueryType::PROJECT, $active, ['cf_23', 'cf_25']),
                    'ada' => $projects->totals($ada, QueryType::PROJECT, $active, ['cf_23', 'cf_25']),
                    'bea' => $projects->totals($bea, QueryType::PROJECT, $active, ['cf_23', 'cf_25']),
                ],
                'label_not_total' => $this->message(fn () => $projects->totals($admin, QueryType::PROJECT, $active, ['cf_22'])),
                'finn_secret_total' => $this->message(fn () => $projects->totals($finn, QueryType::PROJECT, $active, ['cf_24'])),
            ],
            'history' => $this->history($projects, $admin, $ada, $bea),
            'activity' => $this->activity($projects, $admin, $ada),
            'changesets' => [
                'status' => 'n/a',
                'excludes' => $this->scmExcludes(),
            ],
        ];

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/remainder.json', $actual);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Queries \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/QueryRemainderParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/remainder.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Textile and Markdown rendering \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — OpenID Connect \| N\/A \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — live LDAP \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — full REST API \| VERIFIED \|/m', $checklist);
    }

    /**
     * @return array{ids: list<int>, rows: list<array<string, int|string|null>>, totals: array<string, string>}
     */
    private function remaining(IssueQueryRunner $runner, User $actor): array
    {
        $query = new Query;
        $query->type = QueryType::ISSUE;
        $query->name = 'Remaining';
        $query->user_id = $actor->id;
        $query->project_id = null;
        $query->visibility = QueryVisibility::PUBLIC;
        $query->filters = ['status_id' => ['operator' => '*', 'values' => []]];
        $query->column_names = ['estimated_remaining_hours', 'estimated_hours', 'done_ratio'];
        $query->sort_criteria = [['estimated_remaining_hours', 'desc'], ['id', 'asc']];
        $query->group_by = null;
        $query->options = [
            'display_type' => 'list',
            'totalable_names' => ['estimated_remaining_hours'],
        ];

        $view = $runner->present($actor, $query);
        $rows = [];
        foreach ($view->rows as $row) {
            $record = ['issue_id' => $row->issueId];
            foreach ($row->values as $column => $value) {
                $record[$column] = $value;
            }
            $rows[] = $record;
        }

        return [
            'ids' => array_map(static fn (array $row): int => $row['issue_id'], $rows),
            'rows' => $rows,
            'totals' => $runner->totals($actor, $query),
        ];
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<string>  $columns
     * @return array{ids: list<int>, columns: list<string>, rows: list<array<string, string|null>>}
     */
    private function projectSlice(ProjectQueryRunner $runner, User $actor, string $type, array $filters, array $columns): array
    {
        $result = $runner->run($actor, $type, $filters, $columns);

        return [
            'ids' => $result['ids'],
            'columns' => $result['columns'],
            'rows' => $result['rows'],
        ];
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<array{0: string, 1: string}>  $sort
     * @return list<int>
     */
    private function ids(ProjectQueryRunner $runner, User $actor, array $filters, string $type = QueryType::PROJECT, array $sort = []): array
    {
        return $runner->run($actor, $type, $filters, ['name'], $sort)['ids'];
    }

    /**
     * @return array<string, mixed>
     */
    private function history(ProjectQueryRunner $runner, User $admin, User $ada, User $bea): array
    {
        $public = Journal::query()->create([
            'journalized_type' => 'Project',
            'journalized_id' => 1,
            'user_id' => 2,
            'notes' => null,
            'private_notes' => false,
            'created_on' => '2026-09-02 08:00:00',
            'updated_on' => '2026-09-02 08:00:00',
        ]);
        JournalDetail::query()->create([
            'journal_id' => $public->id,
            'property' => 'cf',
            'prop_key' => '26',
            'old_value' => 'beta',
            'value' => 'alpha',
        ]);
        $private = Journal::query()->create([
            'journalized_type' => 'Project',
            'journalized_id' => 1,
            'user_id' => 2,
            'notes' => 'hidden project note',
            'private_notes' => true,
            'created_on' => '2026-09-02 09:00:00',
            'updated_on' => '2026-09-02 09:00:00',
        ]);
        JournalDetail::query()->create([
            'journal_id' => $private->id,
            'property' => 'cf',
            'prop_key' => '26',
            'old_value' => 'gamma',
            'value' => 'alpha',
        ]);

        $lane = static fn (string $operator, string $value): array => [
            'cf_26' => ['operator' => $operator, 'values' => [$value]],
        ];

        return [
            'ev_beta' => $this->ids($runner, $admin, $lane('ev', 'beta')),
            'cf_beta' => $this->ids($runner, $admin, $lane('cf', 'beta')),
            'not_ev_alpha' => $this->ids($runner, $admin, $lane('!ev', 'alpha')),
            'ada_private' => $this->ids($runner, $ada, $lane('cf', 'gamma')),
            'admin_private' => $this->ids($runner, $admin, $lane('cf', 'gamma')),
            'bea_private' => $this->ids($runner, $bea, $lane('cf', 'gamma')),
        ];
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function activity(ProjectQueryRunner $runner, User $admin, User $ada): array
    {
        $dates = [
            'baseline_admin' => $this->dates($runner, $admin),
            'baseline_ada' => $this->dates($runner, $ada),
        ];

        News::query()->create([
            'project_id' => 1,
            'author_id' => 2,
            'title' => 'Pin news',
            'summary' => '',
            'created_on' => '2026-09-10 08:00:00',
        ]);
        Document::query()->create([
            'project_id' => 1,
            'category_id' => 0,
            'title' => 'Pin doc',
            'created_on' => '2026-09-11 08:00:00',
        ]);
        Attachment::query()->create([
            'container_type' => 'Project',
            'container_id' => 1,
            'author_id' => 2,
            'filename' => 'core.txt',
            'disk_filename' => 'core.txt',
            'created_on' => '2026-09-12 08:00:00',
        ]);
        Attachment::query()->create([
            'container_type' => 'Version',
            'container_id' => 3,
            'author_id' => 2,
            'filename' => 'shared.txt',
            'disk_filename' => 'shared.txt',
            'created_on' => '2026-09-15 08:00:00',
        ]);
        $wiki = Wiki::query()->create([
            'project_id' => 1,
            'start_page' => 'Home',
            'status' => 1,
        ]);
        $page = WikiPage::query()->create([
            'wiki_id' => $wiki->id,
            'title' => 'Home',
            'created_on' => '2026-09-13 08:00:00',
            'protected' => false,
        ]);
        WikiContent::query()->create([
            'page_id' => $page->id,
            'author_id' => 2,
            'text' => 'pin',
            'updated_on' => '2026-09-13 08:00:00',
            'version' => 1,
        ]);
        $board = Board::query()->create([
            'project_id' => 1,
            'name' => 'General',
            'position' => 1,
        ]);
        Message::query()->create([
            'board_id' => $board->id,
            'author_id' => 2,
            'subject' => 'Pin topic',
            'content' => 'pin',
            'created_on' => '2026-09-14 08:00:00',
            'updated_on' => '2026-09-14 08:00:00',
        ]);

        foreach ([1, 2] as $projectId) {
            foreach (['news', 'documents', 'files', 'wiki', 'boards'] as $name) {
                EnabledModule::query()->create([
                    'project_id' => $projectId,
                    'name' => $name,
                ]);
            }
        }

        $dates['modules_admin'] = $this->dates($runner, $admin);
        $dates['modules_ada'] = $this->dates($runner, $ada);
        $this->grant(['view_news']);
        $dates['ada_news'] = $this->dates($runner, $ada);
        $this->grant(['view_documents']);
        $dates['ada_documents'] = $this->dates($runner, $ada);
        $this->grant(['view_files']);
        $dates['ada_files'] = $this->dates($runner, $ada);
        $this->grant(['view_wiki_edits']);
        $dates['ada_wiki'] = $this->dates($runner, $ada);
        $this->grant(['view_messages']);
        $dates['ada_messages'] = $this->dates($runner, $ada);

        EnabledModule::query()->where('project_id', 1)->where('name', 'boards')->delete();
        $dates['admin_without_boards'] = $this->dates($runner, $admin);
        EnabledModule::query()->where('project_id', 2)->where('name', 'files')->delete();
        $dates['admin_without_child_files'] = $this->dates($runner, $admin);

        return $dates;
    }

    /**
     * @return array<string, string|null>
     */
    private function dates(ProjectQueryRunner $runner, User $actor): array
    {
        $result = $runner->run($actor, QueryType::PROJECT, [
            'status' => ['operator' => '=', 'values' => ['1']],
        ], ['identifier', 'last_activity_date']);
        $dates = [];
        foreach ($result['rows'] as $row) {
            $identifier = $row['identifier'] ?? '';
            $dates[$identifier] = $row['last_activity_date'];
        }

        return $dates;
    }

    /**
     * @param  list<string>  $names
     */
    private function grant(array $names): void
    {
        $role = Role::query()->findOrFail(1);
        $permissions = $role->permissions;
        $this->assertIsArray($permissions);
        $role->permissions = array_values(array_unique([...$permissions, ...$names]));
        $role->save();
    }

    /**
     * @return list<string>
     */
    private function scmExcludes(): array
    {
        $path = Redmine701Fixture::directory().'/manifest.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $excludes = $decoded['excludes'] ?? null;
        $this->assertIsArray($excludes);
        $names = [];
        foreach ($excludes as $name) {
            $this->assertIsString($name);
            $names[] = $name;
        }
        $this->assertSame(Redmine701Fixture::SCM_TABLES, $names);

        return $names;
    }

    private function message(callable $callback): string
    {
        try {
            $callback();
        } catch (DomainException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected a domain exception.');
    }

    private function actor(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user, $login);

        return $user;
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
