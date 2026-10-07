<?php

namespace Tests\Parity;

use App\Domain\DomainException;
use App\Domain\Queries\IssueQueryBoardColumn;
use App\Domain\Queries\IssueQueryRow;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryVisibility;
use App\Domain\Queries\SavedQueryService;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares IssueQuery results to the shared pin.
 *
 * Saved and ad-hoc queries are checked per actor: ids and order, column
 * cells, list and board display, group counts, totals, private/role/public
 * visibility, custom-field filters, and spent hours under
 * time_entries_visibility. Gantt, other query types, custom-field history
 * operators, descendant hour columns, and SCM are not part of this comparison.
 */
class IssueQueryParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_queries_match_the_pin_for_each_actor(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $project = $this->project($this->intField($expected, 'project_id'));
        $saved = app(SavedQueryService::class);

        $visibility = $expected['visibility'] ?? null;
        $this->assertIsArray($visibility);
        foreach ($visibility as $row) {
            $this->assertIsArray($row);
            $login = $row['login'] ?? null;
            $this->assertTrue($login === null || is_string($login));
            $actor = $login === null ? null : $this->actor($login);
            $seen = [];
            foreach ($saved->visible($actor, $project, QueryType::ISSUE) as $query) {
                $seen[] = (int) $query->id;
            }
            $this->assertSame($this->intList($row, 'ids'), $seen, 'visible '.$this->who($login));
        }

        $runs = $expected['saved'] ?? null;
        $this->assertIsArray($runs);
        foreach ($runs as $run) {
            $this->assertIsArray($run);
            $login = $this->stringField($run, 'login');
            $queryId = $this->intField($run, 'query_id');
            $this->assertRun($this->actor($login), $this->savedQuery($queryId), $run, 'query '.$queryId.' '.$login);
        }

        $denied = $expected['denied_saved'] ?? null;
        $this->assertIsArray($denied);
        foreach ($denied as $row) {
            $this->assertIsArray($row);
            $login = $this->stringField($row, 'login');
            $queryId = $this->intField($row, 'query_id');
            $this->assertDenied(
                $this->actor($login),
                $this->savedQuery($queryId),
                $this->stringField($row, 'message'),
                'query '.$queryId.' '.$login,
            );
        }
    }

    public function test_adhoc_filters_sorts_groups_and_columns_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $cases = $expected['adhoc'] ?? null;
        $this->assertIsArray($cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $name = $this->stringField($case, 'name');
            $runs = $case['runs'] ?? null;
            $this->assertIsArray($runs);
            foreach ($runs as $run) {
                $this->assertIsArray($run);
                $login = $this->stringField($run, 'login');
                $actor = $this->actor($login);
                $this->assertRun($actor, $this->adhoc($actor, $case), $run, $name.' '.$login);
            }
        }

        $denied = $expected['denied_adhoc'] ?? null;
        $this->assertIsArray($denied);
        foreach ($denied as $case) {
            $this->assertIsArray($case);
            $login = $this->stringField($case, 'login');
            $actor = $this->actor($login);
            $this->assertDenied(
                $actor,
                $this->adhoc($actor, $case),
                $this->stringField($case, 'message'),
                $this->stringField($case, 'name').' '.$login,
            );
        }
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Queries \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/IssueQueryParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/results.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Journals and private notes \| NOT VERIFIED \|/m', $checklist);
    }

    /**
     * @param  array<mixed>  $expected
     */
    private function assertRun(User $actor, Query $query, array $expected, string $label): void
    {
        $runner = app(IssueQueryRunner::class);
        $view = $runner->present($actor, $query);
        /** @var Collection<int, Issue> $issues */
        $issues = $runner->execute($actor, $query)->get();

        $ids = [];
        foreach ($issues as $issue) {
            $ids[] = (int) $issue->id;
        }
        $this->assertSame($this->intList($expected, 'ids'), $ids, $label);

        if (array_key_exists('display_type', $expected)) {
            $this->assertSame($this->stringField($expected, 'display_type'), $view->displayType, $label);
        }
        if (array_key_exists('columns', $expected)) {
            $this->assertSame($this->stringList($expected, 'columns'), $view->columns, $label);
        }
        if (array_key_exists('rows', $expected)) {
            $this->assertSame($this->rows($expected, 'rows'), $this->actualRows($view->rows), $label);
        }
        if (array_key_exists('board', $expected)) {
            $this->assertSame($this->board($expected), $this->actualBoard($view->board), $label);
        }
        if (array_key_exists('groups', $expected)) {
            $stored = $expected['groups'];
            if ($stored === null) {
                $this->assertTrue($query->group_by === null || $query->group_by === '', $label);
            } else {
                $this->assertIsString($query->group_by);
                $this->assertSame($this->expectedGroups($stored), $this->actualGroups($issues, $query->group_by), $label);
            }
        }
        if (array_key_exists('totals', $expected)) {
            $this->assertSame($this->stringMap($expected, 'totals'), $runner->totals($actor, $query), $label);
        }
    }

    private function assertDenied(User $actor, Query $query, string $message, string $label): void
    {
        try {
            app(IssueQueryRunner::class)->present($actor, $query);
            $this->fail($label.' should have been rejected.');
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage(), $label);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function adhoc(User $actor, array $spec): Query
    {
        $filters = $spec['filters'] ?? null;
        $this->assertIsArray($filters);
        $sort = $spec['sort'] ?? [['id', 'asc']];
        $this->assertIsArray($sort);
        $projectId = array_key_exists('project_id', $spec) ? $spec['project_id'] : 1;
        $this->assertTrue($projectId === null || is_int($projectId));
        $columns = array_key_exists('column_names', $spec) ? $spec['column_names'] : ['id'];
        $this->assertTrue($columns === null || is_array($columns));
        $options = array_key_exists('options', $spec) ? $spec['options'] : ['display_type' => 'list'];
        $this->assertTrue($options === null || is_array($options));
        $groupBy = $spec['group_by'] ?? null;
        $this->assertTrue($groupBy === null || is_string($groupBy));

        $query = new Query;
        $query->type = QueryType::ISSUE;
        $query->name = $this->stringField($spec, 'name');
        $query->user_id = $actor->id;
        $query->project_id = $projectId;
        $query->visibility = QueryVisibility::PUBLIC;
        $query->filters = $filters;
        $query->column_names = $columns;
        $query->sort_criteria = $sort;
        $query->group_by = $groupBy;
        $query->options = $options;

        return $query;
    }

    private function savedQuery(int $id): Query
    {
        $query = Query::query()->find($id);
        $this->assertInstanceOf(Query::class, $query);

        return $query;
    }

    private function actor(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user, $login);

        return $user;
    }

    private function project(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    /**
     * @param  list<IssueQueryRow>  $rows
     * @return list<array<string, int|string|null>>
     */
    private function actualRows(array $rows): array
    {
        $actual = [];
        foreach ($rows as $row) {
            $record = ['issue_id' => $row->issueId];
            foreach ($row->values as $column => $value) {
                $record[$column] = $value;
            }
            $actual[] = $record;
        }

        return $actual;
    }

    /**
     * @param  list<IssueQueryBoardColumn>  $columns
     * @return list<array{status_id: int, name: string, ids: list<int>}>
     */
    private function actualBoard(array $columns): array
    {
        $board = [];
        foreach ($columns as $column) {
            $board[] = [
                'status_id' => $column->statusId,
                'name' => $column->name,
                'ids' => $column->issueIds,
            ];
        }

        return $board;
    }

    /**
     * @param  Collection<int, Issue>  $issues
     * @return list<array{key: int|string|null, count: int, ids: list<int>}>
     */
    private function actualGroups(Collection $issues, string $groupBy): array
    {
        $attribute = $this->groupAttribute($groupBy);
        $groups = [];
        $index = [];
        foreach ($issues as $issue) {
            $key = $this->groupKey($issue->getAttribute($attribute));
            $token = is_int($key) ? 'i:'.$key : (is_string($key) ? 's:'.$key : 'n');
            if (! isset($index[$token])) {
                $index[$token] = count($groups);
                $groups[] = ['key' => $key, 'count' => 0, 'ids' => []];
            }
            $slot = $index[$token];
            $groups[$slot]['ids'][] = (int) $issue->id;
            $groups[$slot]['count']++;
        }

        return $groups;
    }

    private function groupAttribute(string $groupBy): string
    {
        return match ($groupBy) {
            'tracker', 'tracker_id' => 'tracker_id',
            'status', 'status_id' => 'status_id',
            'priority', 'priority_id' => 'priority_id',
            'author', 'author_id' => 'author_id',
            'assigned_to', 'assigned_to_id' => 'assigned_to_id',
            'category', 'category_id' => 'category_id',
            'fixed_version', 'fixed_version_id' => 'fixed_version_id',
            'project', 'project_id' => 'project_id',
            'subject' => 'subject',
            'id' => 'id',
            default => $this->unexpectedGroup($groupBy),
        };
    }

    private function unexpectedGroup(string $groupBy): never
    {
        $this->fail('Unsupported group_by '.$groupBy.'.');
    }

    private function groupKey(mixed $raw): int|string|null
    {
        if (is_int($raw) || is_string($raw) || $raw === null) {
            return is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : $raw;
        }

        $this->fail('Group key is not a scalar.');
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/queries/results.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<int>
     */
    private function intList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value);
        $list = [];
        foreach ($value as $item) {
            $this->assertIsInt($item);
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<string>
     */
    private function stringList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value);
        $list = [];
        foreach ($value as $item) {
            $this->assertIsString($item);
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $row
     * @return array<string, string>
     */
    private function stringMap(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value);
        $map = [];
        foreach ($value as $name => $item) {
            $this->assertIsString($name);
            $this->assertIsString($item);
            $map[$name] = $item;
        }

        return $map;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<array<string, int|string|null>>
     */
    private function rows(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value);
        $rows = [];
        foreach ($value as $item) {
            $this->assertIsArray($item);
            $record = [];
            foreach ($item as $column => $cell) {
                $this->assertIsString($column);
                $this->assertTrue($cell === null || is_string($cell) || is_int($cell));
                $record[$column] = $cell;
            }
            $rows[] = $record;
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<array{status_id: int, name: string, ids: list<int>}>
     */
    private function board(array $row): array
    {
        $value = $row['board'] ?? null;
        $this->assertIsArray($value);
        $board = [];
        foreach ($value as $column) {
            $this->assertIsArray($column);
            $board[] = [
                'status_id' => $this->intField($column, 'status_id'),
                'name' => $this->stringField($column, 'name'),
                'ids' => $this->intList($column, 'ids'),
            ];
        }

        return $board;
    }

    /**
     * @return list<array{key: int|string|null, count: int, ids: list<int>}>
     */
    private function expectedGroups(mixed $stored): array
    {
        $this->assertIsArray($stored);
        $groups = [];
        foreach ($stored as $group) {
            $this->assertIsArray($group);
            $key = $group['key'] ?? null;
            $this->assertTrue($key === null || is_int($key) || is_string($key));
            $groups[] = [
                'key' => $key,
                'count' => $this->intField($group, 'count'),
                'ids' => $this->intList($group, 'ids'),
            ];
        }

        return $groups;
    }

    private function who(mixed $login): string
    {
        return is_string($login) ? $login : 'anonymous';
    }
}
