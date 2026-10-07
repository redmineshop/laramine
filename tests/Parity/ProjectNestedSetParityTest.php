<?php

namespace Tests\Parity;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\ManagedRoleGuard;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Domain\Issues\IssueTree;
use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\TreeException;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares project and issue nested-set coordinates, and the inherit_members
 * walk after a parent change, to the shared pin.
 *
 * Archived and closed project statuses are compared to status.json.
 */
class ProjectNestedSetParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_project_and_issue_coordinates_match_the_fixture_files(): void
    {
        Redmine701Fixture::load();
        $this->assertTableCoordinates('projects', ['parent_id', 'lft', 'rgt']);
        $this->assertTableCoordinates('issues', ['parent_id', 'root_id', 'lft', 'rgt']);
    }

    public function test_move_under_a_descendant_keeps_the_pin_coordinates(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $reject = $expected['reject_descendant'];
        $this->assertIsArray($reject);
        $projectId = $reject['project_id'] ?? null;
        $underId = $reject['under_id'] ?? null;
        $this->assertIsInt($projectId);
        $this->assertIsInt($underId);
        $project = Project::query()->find($projectId);
        $under = Project::query()->find($underId);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertInstanceOf(Project::class, $under);

        try {
            app(ProjectService::class)->move($project, $under);
            $this->fail('Moving a project under its descendant should fail.');
        } catch (TreeException) {
            $this->assertTableCoordinates('projects', ['parent_id', 'lft', 'rgt']);
        }
    }

    public function test_move_rewalks_inherit_members_and_the_nested_set(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $move = $expected['move'];
        $this->assertIsArray($move);
        $projects = app(ProjectService::class);
        $memberships = app(MembershipService::class);

        $sideSpec = $move['side'] ?? null;
        $grandSpec = $move['grandchild'] ?? null;
        $memberSpec = $move['membership'] ?? null;
        $this->assertIsArray($sideSpec);
        $this->assertIsArray($grandSpec);
        $this->assertIsArray($memberSpec);

        $side = $projects->create([
            'name' => $this->stringField($sideSpec, 'name'),
            'identifier' => $this->stringField($sideSpec, 'identifier'),
        ]);
        $parent = Project::query()->find($this->intField($grandSpec, 'parent_id'));
        $this->assertInstanceOf(Project::class, $parent);
        $projects->create([
            'name' => $this->stringField($grandSpec, 'name'),
            'identifier' => $this->stringField($grandSpec, 'identifier'),
            'inherit_members' => true,
        ], $parent);

        $login = $this->stringField($memberSpec, 'login');
        $user = User::query()->where('login', $login)->first();
        $role = Role::query()->find($this->intField($memberSpec, 'role_id'));
        $this->assertInstanceOf(User::class, $user);
        $this->assertInstanceOf(Role::class, $role);
        $memberships->assignRole($side, $user, $role);

        $moved = Project::query()->find($this->intField($move, 'move_project_id'));
        $target = Project::query()->where('identifier', $this->stringField($move, 'move_under'))->first();
        $this->assertInstanceOf(Project::class, $moved);
        $this->assertInstanceOf(Project::class, $target);
        $projects->move($moved, $target);
        $projects->assertConsistent();

        $coordinates = $move['projects'] ?? null;
        $this->assertIsArray($coordinates);
        foreach ($coordinates as $row) {
            $this->assertIsArray($row);
            $this->assertProjectCoordinate($row);
        }

        $inherited = $move['inherited'] ?? null;
        $direct = $move['direct'] ?? null;
        $this->assertIsArray($inherited);
        $this->assertIsArray($direct);
        foreach ($inherited as $row) {
            $this->assertIsArray($row);
            $this->assertMemberSource($row, false);
        }
        foreach ($direct as $row) {
            $this->assertIsArray($row);
            $this->assertMemberSource($row, true);
        }
    }

    public function test_issue_extract_matches_the_recorded_coordinates(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $extract = $expected['issue_extract'];
        $this->assertIsArray($extract);
        $issue = Issue::query()->find($this->intField($extract, 'issue_id'));
        $this->assertInstanceOf(Issue::class, $issue);
        app(IssueTree::class)->move($issue, null);
        app(IssueTree::class)->assertConsistent();

        $rows = $extract['issues'] ?? null;
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $stored = DB::table('issues')->where('id', $this->intField($row, 'id'))->first();
            $this->assertNotNull($stored);
            $this->assertSame($this->nullableInt($row, 'parent_id'), $stored->parent_id === null ? null : (int) $stored->parent_id);
            $this->assertSame($this->intField($row, 'root_id'), (int) $stored->root_id);
            $this->assertSame($this->intField($row, 'lft'), (int) $stored->lft);
            $this->assertSame($this->intField($row, 'rgt'), (int) $stored->rgt);
        }
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Projects and issue nested sets \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/ProjectNestedSetParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/projects-nested-set/tree.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/projects-nested-set/status.json', $checklist);
    }

    public function test_archived_and_closed_statuses_match_the_recorded_gates(): void
    {
        Redmine701Fixture::load();
        $expected = $this->statusExpectation();
        $closed = $expected['closed'];
        $archived = $expected['archived'];
        $this->assertIsArray($closed);
        $this->assertIsArray($archived);
        $this->assertClosedProject($closed);
        $this->assertArchivedProject($archived);
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertClosedProject(array $spec): void
    {
        $project = $this->requireProject($this->intField($spec, 'project_id'));
        $role = Role::query()->find($this->intField($spec, 'grant_role_id'));
        $this->assertInstanceOf(Role::class, $role);
        $grant = $spec['grant_permissions'];
        $this->assertIsArray($grant);
        $names = $role->permissions;
        foreach ($grant as $name) {
            $this->assertIsString($name);
            $names[] = $name;
        }
        $role->permissions = array_values(array_unique($names));
        $role->save();
        $project->status = $this->intField($spec, 'status');
        $project->save();

        $this->assertVisibilityMatrix($spec, $project);
        $this->assertPermissionMatrix($spec, $project);
        $module = $spec['enabled_module'];
        $this->assertIsArray($module);
        app(ProjectService::class)->enableModule($project, $this->stringField($module, 'name'));
        $checks = $module['checks'];
        $this->assertIsArray($checks);
        $permissions = app(PermissionService::class);
        foreach ($checks as $case) {
            $this->assertIsArray($case);
            $this->assertSame(
                (bool) $case['allowed'],
                $permissions->allowed(
                    $this->findUser($case['login'] ?? null),
                    $this->stringField($case, 'permission'),
                    $project->fresh() ?? $project,
                ),
            );
        }
        $this->assertDeniedEdits($spec);
        $this->assertListedIssueIds($spec);
        $this->assertListedTimeEntries($spec, $project);
        $membership = $spec['membership'];
        $this->assertIsArray($membership);
        $this->assertManagedAssignmentDenied($membership, $project);
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertArchivedProject(array $spec): void
    {
        $project = $this->requireProject($this->intField($spec, 'project_id'));
        $project->status = $this->intField($spec, 'status');
        $project->is_public = (bool) $spec['is_public'];
        $project->save();

        $this->assertVisibilityMatrix($spec, $project);
        $this->assertPermissionMatrix($spec, $project);
        $seeing = $spec['see_issue'];
        $this->assertIsArray($seeing);
        $visibility = app(IssueVisibility::class);
        foreach ($seeing as $case) {
            $this->assertIsArray($case);
            $issue = Issue::query()->find($this->intField($case, 'issue_id'));
            $this->assertInstanceOf(Issue::class, $issue);
            $login = $case['login'] ?? null;
            $this->assertTrue($login === null || is_string($login));
            $this->assertSame((bool) $case['visible'], $visibility->canSee($this->findUser($login), $issue));
        }
        $this->assertListedIssueIds($spec);
        $this->assertListedTimeEntries($spec, $project);
        $edit = $spec['edit'];
        $this->assertIsArray($edit);
        $this->assertDeniedEdits(['edits' => [$edit]]);
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertVisibilityMatrix(array $spec, Project $project): void
    {
        $permissions = app(PermissionService::class);
        $rows = $spec['visible'];
        $this->assertIsArray($rows);
        foreach ($rows as $case) {
            $this->assertIsArray($case);
            $login = $case['login'] ?? null;
            $this->assertTrue($login === null || is_string($login));
            $this->assertSame((bool) $case['visible'], $permissions->projectVisible($this->findUser($login), $project->fresh() ?? $project));
        }
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertPermissionMatrix(array $spec, Project $project): void
    {
        $permissions = app(PermissionService::class);
        $rows = $spec['permissions'];
        $this->assertIsArray($rows);
        foreach ($rows as $case) {
            $this->assertIsArray($case);
            $login = $case['login'] ?? null;
            $this->assertTrue($login === null || is_string($login));
            $this->assertSame(
                (bool) $case['allowed'],
                $permissions->allowed($this->findUser($login), $this->stringField($case, 'permission'), $project->fresh() ?? $project),
                ($login ?? 'guest').' '.$this->stringField($case, 'permission'),
            );
        }
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertDeniedEdits(array $spec): void
    {
        $issues = app(IssueService::class);
        $rows = $spec['edits'];
        $this->assertIsArray($rows);
        foreach ($rows as $case) {
            $this->assertIsArray($case);
            $actor = $this->findUser($this->stringField($case, 'login'));
            $issue = Issue::query()->find($this->intField($case, 'issue_id'));
            $this->assertInstanceOf(User::class, $actor);
            $this->assertInstanceOf(Issue::class, $issue);
            try {
                $issues->update($actor, $issue, ['subject' => 'Closed edit']);
                $this->fail($this->stringField($case, 'error'));
            } catch (DomainException $exception) {
                $this->assertStringContainsString($this->stringField($case, 'error'), $exception->getMessage());
            }
            $this->assertSame($this->stringField($case, 'stored_subject'), DB::table('issues')->where('id', $issue->id)->value('subject'));
        }
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertListedIssueIds(array $spec): void
    {
        $runner = app(IssueQueryRunner::class);
        $rows = $spec['issue_ids'];
        $this->assertIsArray($rows);
        foreach ($rows as $case) {
            $this->assertIsArray($case);
            $login = $case['login'] ?? null;
            $this->assertIsString($login);
            $projectId = $case['project_id'] ?? null;
            $this->assertTrue($projectId === null || is_int($projectId));
            $project = is_int($projectId) ? $this->requireProject($projectId) : null;
            $ids = $runner->preview($this->findUser($login), $project, [])->orderBy('id')->pluck('issues.id')->all();
            $actual = [];
            foreach ($ids as $id) {
                if (is_numeric($id)) {
                    $actual[] = (int) $id;
                }
            }
            $this->assertSame($this->idList($case), $actual, $login);
        }
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertListedTimeEntries(array $spec, Project $project): void
    {
        $visibility = app(TimeEntryVisibility::class);
        $rows = $spec['time_entry_ids'];
        $this->assertIsArray($rows);
        foreach ($rows as $case) {
            $this->assertIsArray($case);
            $actor = $this->findUser($this->stringField($case, 'login'));
            $this->assertInstanceOf(User::class, $actor);
            $ids = $visibility
                ->apply(TimeEntry::query()->where('project_id', $project->id), $actor, $project->fresh() ?? $project)
                ->orderBy('id')
                ->pluck('id')
                ->all();
            $actual = [];
            foreach ($ids as $id) {
                if (is_numeric($id)) {
                    $actual[] = (int) $id;
                }
            }
            $this->assertSame($this->idList($case), $actual);
        }
    }

    /**
     * @param  array<mixed>  $spec
     */
    private function assertManagedAssignmentDenied(array $spec, Project $project): void
    {
        $actor = $this->findUser($this->stringField($spec, 'actor'));
        $principal = $this->findUser($this->stringField($spec, 'principal'));
        $role = Role::query()->find($this->intField($spec, 'role_id'));
        $this->assertInstanceOf(User::class, $actor);
        $this->assertInstanceOf(User::class, $principal);
        $this->assertInstanceOf(Role::class, $role);
        try {
            app(ManagedRoleGuard::class)->assign($actor, $project->fresh() ?? $project, $principal, $role);
            $this->fail($this->stringField($spec, 'error'));
        } catch (DomainException $exception) {
            $this->assertStringContainsString($this->stringField($spec, 'error'), $exception->getMessage());
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertTableCoordinates(string $table, array $columns): void
    {
        $path = Redmine701Fixture::directory().'/'.$table.'.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $stored = DB::table($table)->where('id', $this->intField($row, 'id'))->first();
            $this->assertNotNull($stored);
            foreach ($columns as $column) {
                $expected = $row[$column] ?? null;
                $this->assertTrue($expected === null || is_int($expected));
                $actual = $stored->{$column};
                $this->assertSame($expected, $actual === null ? null : (int) $actual, $table.'.'.$column);
            }
        }
    }

    /**
     * @param  array<mixed>  $row
     */
    private function assertProjectCoordinate(array $row): void
    {
        $id = isset($row['id']) ? $this->intField($row, 'id') : $this->projectIdByIdentifier($this->stringField($row, 'identifier'));
        $stored = DB::table('projects')->where('id', $id)->first();
        $this->assertNotNull($stored);
        if (array_key_exists('parent_identifier', $row)) {
            $parent = $this->projectIdByIdentifier($this->stringField($row, 'parent_identifier'));
            $this->assertSame($parent, (int) $stored->parent_id);
        } else {
            $this->assertSame($this->nullableInt($row, 'parent_id'), $stored->parent_id === null ? null : (int) $stored->parent_id);
        }
        $this->assertSame($this->intField($row, 'lft'), (int) $stored->lft);
        $this->assertSame($this->intField($row, 'rgt'), (int) $stored->rgt);
    }

    /**
     * @param  array<mixed>  $row
     */
    private function assertMemberSource(array $row, bool $direct): void
    {
        $projectId = isset($row['project_id'])
            ? $this->intField($row, 'project_id')
            : $this->projectIdByIdentifier($this->stringField($row, 'identifier'));
        $roleId = $this->memberRoleId($projectId, $this->stringField($row, 'login'), $this->intField($row, 'role_id'));
        $inherited = DB::table('member_roles')->where('id', $roleId)->value('inherited_from');
        if ($direct) {
            $this->assertNull($inherited);

            return;
        }

        $source = $row['inherited_from'] ?? null;
        $this->assertIsArray($source);
        $sourceProject = isset($source['project_id'])
            ? $this->intField($source, 'project_id')
            : $this->projectIdByIdentifier($this->stringField($source, 'identifier'));
        $sourceId = $this->memberRoleId($sourceProject, $this->stringField($source, 'login'), $this->intField($source, 'role_id'));
        $this->assertNotNull($inherited);
        $this->assertSame($sourceId, (int) $inherited);
    }

    private function memberRoleId(int $projectId, string $login, int $roleId): int
    {
        $userId = DB::table('users')->where('login', $login)->value('id');
        $this->assertNotNull($userId);
        $id = DB::table('member_roles')
            ->join('members', 'members.id', '=', 'member_roles.member_id')
            ->where('members.project_id', $projectId)
            ->where('members.user_id', (int) $userId)
            ->where('member_roles.role_id', $roleId)
            ->value('member_roles.id');
        $this->assertNotNull($id, $login.' role '.$roleId.' on project '.$projectId);

        return (int) $id;
    }

    private function projectIdByIdentifier(string $identifier): int
    {
        $id = DB::table('projects')->where('identifier', $identifier)->value('id');
        $this->assertNotNull($id);

        return (int) $id;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        $this->assertTrue($value === null || is_int($value));

        return $value;
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

    private function requireProject(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function findUser(mixed $login): ?User
    {
        if ($login === null) {
            return null;
        }
        $this->assertIsString($login);
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param  array<mixed>  $case
     * @return list<int>
     */
    private function idList(array $case): array
    {
        $ids = $case['ids'] ?? null;
        $this->assertIsArray($ids);
        $list = [];
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $list[] = $id;
        }

        return $list;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusExpectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/projects-nested-set/status.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/projects-nested-set/tree.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }
}
