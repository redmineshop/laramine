<?php

namespace Tests\Parity;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\ManagedRoleGuard;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Domain\Acl\UserVisibility;
use App\Domain\DomainException;
use App\Domain\Issues\History\IssueHistorySides;
use App\Domain\Issues\IssueService;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares membership allow/deny, users_visibility, inherit_members,
 * per-tracker masks, managed roles, and spent-time visibility to the shared pin.
 *
 * Non-issue modules and the user directory are not part of this comparison.
 */
class IdentityAclParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_permissions_match_the_recorded_matrix(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $permissions = app(PermissionService::class);
        $cases = $expected['permissions'];
        $this->assertIsArray($cases);

        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $login = $case['login'] ?? null;
            $projectId = $case['project_id'] ?? null;
            $permission = $case['permission'] ?? null;
            $allowed = $case['allowed'] ?? null;
            $this->assertTrue($login === null || is_string($login));
            $this->assertIsInt($projectId);
            $this->assertIsString($permission);
            $this->assertIsBool($allowed);
            $project = Project::query()->find($projectId);
            $this->assertInstanceOf(Project::class, $project);
            $this->assertSame(
                $allowed,
                $permissions->allowed($this->actor(is_string($login) ? $login : null), $permission, $project),
                $permission.' for '.($login ?? 'guest').' on project '.$projectId,
            );
        }
    }

    public function test_users_visibility_matches_the_recorded_matrix(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $visibility = app(UserVisibility::class);
        $cases = $expected['users_visibility'];
        $this->assertIsArray($cases);

        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $viewer = $case['viewer'] ?? null;
            $subject = $case['subject'] ?? null;
            $visible = $case['visible'] ?? null;
            $this->assertTrue($viewer === null || is_string($viewer));
            $this->assertIsString($subject);
            $this->assertIsBool($visible);
            $subjectUser = $this->actor($subject);
            $this->assertInstanceOf(User::class, $subjectUser);
            $this->assertSame(
                $visible,
                $visibility->canSee($this->actor(is_string($viewer) ? $viewer : null), $subjectUser),
                ($viewer ?? 'guest').' seeing '.$subject,
            );
        }
    }

    public function test_assignee_visibility_follows_the_recorded_writes(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $cases = $expected['assignees'];
        $this->assertIsArray($cases);
        $issues = app(IssueService::class);

        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $login = $case['login'] ?? null;
            $issueId = $case['issue_id'] ?? null;
            $assigneeLogin = $case['assignee_login'] ?? null;
            $error = $case['error'] ?? null;
            $storedLogin = $case['stored_login'] ?? null;
            $this->assertIsString($login);
            $this->assertIsInt($issueId);
            $this->assertIsString($assigneeLogin);
            $this->assertTrue($error === null || is_string($error));
            $this->assertIsString($storedLogin);
            $actor = $this->actor($login);
            $assignee = $this->actor($assigneeLogin);
            $issue = Issue::query()->find($issueId);
            $this->assertInstanceOf(User::class, $actor);
            $this->assertInstanceOf(User::class, $assignee);
            $this->assertInstanceOf(Issue::class, $issue);

            try {
                $issues->update($actor, $issue->fresh() ?? $issue, [
                    'assigned_to_id' => $assignee->id,
                ]);
                $this->assertNull($error);
            } catch (DomainException $exception) {
                $this->assertIsString($error);
                $this->assertStringContainsString($error, $exception->getMessage());
            }

            $storedId = DB::table('issues')->where('id', $issueId)->value('assigned_to_id');
            $this->assertSame($this->userId($storedLogin), (int) $storedId);
        }
    }

    public function test_inherit_members_walk_chains_the_group_role(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $walk = $expected['inherit_members_walk'];
        $this->assertIsArray($walk);
        $assign = $walk['assign'] ?? null;
        $copies = $walk['copies'] ?? null;
        $this->assertIsArray($assign);
        $this->assertIsArray($copies);
        $projectId = $assign['project_id'] ?? null;
        $login = $assign['login'] ?? null;
        $roleId = $assign['role_id'] ?? null;
        $this->assertIsInt($projectId);
        $this->assertIsString($login);
        $this->assertIsInt($roleId);
        $project = Project::query()->find($projectId);
        $principal = $this->actor($login);
        $role = Role::query()->find($roleId);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertInstanceOf(User::class, $principal);
        $this->assertInstanceOf(Role::class, $role);

        app(MembershipService::class)->assignRole($project, $principal, $role);

        foreach ($copies as $copy) {
            $this->assertIsArray($copy);
            $this->assertInheritedCopy($copy);
        }
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Identity, membership, and permissions \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/IdentityAclParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/identity-acl/allow-deny.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/identity-acl/tracker-mask.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/identity-acl/managed-roles.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/identity-acl/time-entries.json', $checklist);
        $this->assertDoesNotMatchRegularExpression(
            '/^\| (?!P0 table and column layout \|)(?!Users and authentication)(?!Identity, membership, and permissions \|)(?!Projects and issue nested sets \|)(?!Workflows \|)(?!Custom fields \|)(?!Queries \|)(?!Journals and private notes \|)(?!Time entries and attachments \|)(?!Activity \|)(?!Activity for news, documents, and files \|)(?!Activity for wiki and messages \|)(?!News, documents, and files \|)(?!Notifications for news, documents, and files \|)(?!Notifications for messages and wiki \|)(?!Wiki \|)(?!Boards and forums \|)(?!Calendar and Gantt \|)(?!Textile and Markdown rendering \|)[^|\n]+\| VERIFIED \|/m',
            $checklist,
        );
    }

    public function test_tracker_masks_match_the_recorded_matrix(): void
    {
        Redmine701Fixture::load();
        $expected = $this->recorded('expectations/identity-acl/tracker-mask.json');
        $role = Role::query()->find($this->intField($expected, 'role_id'));
        $this->assertInstanceOf(Role::class, $role);
        $settings = $expected['settings'] ?? null;
        $this->assertIsArray($settings);
        $role->settings = $settings;
        $role->save();
        DB::table('member_roles')->where('id', $this->intField($expected, 'drop_member_role_id'))->delete();

        $permissions = app(PermissionService::class);
        $cases = $expected['permissions'];
        $this->assertIsArray($cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $tracker = $this->optionalTracker($case);
            $login = $case['login'] ?? null;
            $this->assertIsString($login);
            $allowed = $case['allowed'] ?? null;
            $this->assertIsBool($allowed);
            $this->assertSame(
                $allowed,
                $permissions->allowed(
                    $this->actor($login),
                    $this->stringField($case, 'permission'),
                    $this->findProject($this->intField($case, 'project_id')),
                    $tracker,
                ),
                $this->stringField($case, 'permission').' '.$login,
            );
        }

        $visibility = app(IssueVisibility::class);
        $visible = $expected['visible_issue_ids'];
        $this->assertIsArray($visible);
        foreach ($visible as $case) {
            $this->assertIsArray($case);
            $this->assertSame($this->idList($case), $this->visibleIssueIds($visibility, $case));
        }

        $second = $expected['second_role'];
        $this->assertIsArray($second);
        $holder = $this->actor($this->stringField($second, 'login'));
        $project = $this->findProject($this->intField($second, 'project_id'));
        $observer = Role::query()->find($this->intField($second, 'role_id'));
        $this->assertInstanceOf(User::class, $holder);
        $this->assertInstanceOf(Role::class, $observer);
        app(MembershipService::class)->assignRole($project, $holder, $observer);
        $this->assertSame($this->idList($second), $this->visibleIssueIds($visibility, $second));

        $issues = app(IssueService::class);
        $writes = $expected['writes'];
        $this->assertIsArray($writes);
        foreach ($writes as $case) {
            $this->assertIsArray($case);
            $this->assertIssueWrite($issues, $case);
        }

        $creates = $expected['creates'];
        $this->assertIsArray($creates);
        foreach ($creates as $case) {
            $this->assertIsArray($case);
            $this->assertIssueCreate($issues, $case);
        }

        $empty = $expected['empty_delete'];
        $this->assertIsArray($empty);
        $manager = Role::query()->find($this->intField($empty, 'role_id'));
        $this->assertInstanceOf(Role::class, $manager);
        $manager->settings = [
            'permissions_all_trackers' => ['delete_issues' => '0'],
            'permissions_tracker_ids' => ['delete_issues' => []],
        ];
        $manager->save();
        $managerProject = $this->findProject($this->intField($empty, 'project_id'));
        $tracker = Tracker::query()->find($this->intField($empty, 'tracker_id'));
        $this->assertInstanceOf(Tracker::class, $tracker);
        $bea = $this->actor($this->stringField($empty, 'login'));
        $this->assertSame(
            (bool) $empty['allowed_without_tracker'],
            $permissions->allowed($bea, 'delete_issues', $managerProject),
        );
        $this->assertSame(
            (bool) $empty['allowed_with_tracker'],
            $permissions->allowed($bea, 'delete_issues', $managerProject, $tracker),
        );
    }

    public function test_managed_roles_match_the_recorded_matrix(): void
    {
        Redmine701Fixture::load();
        $expected = $this->recorded('expectations/identity-acl/managed-roles.json');
        $manager = Role::query()->find($this->intField($expected, 'grant_manage_members_role_id'));
        $this->assertInstanceOf(Role::class, $manager);
        $names = $manager->permissions;
        $names[] = 'manage_members';
        $manager->permissions = array_values(array_unique($names));
        $manager->save();
        $setup = $expected['coordinator'];
        $this->assertIsArray($setup);
        $coordinator = Role::query()->create([
            'name' => $this->stringField($setup, 'name'),
            'builtin' => 0,
            'assignable' => true,
            'all_roles_managed' => false,
            'permissions' => $setup['permissions'],
            'issues_visibility' => 'default',
            'users_visibility' => 'members_of_visible_projects',
            'time_entries_visibility' => 'all',
        ]);
        $coordinator->managedRoles()->attach($this->intField($setup, 'managed_role_id'));
        $holder = $this->actor($this->stringField($setup, 'holder'));
        $this->assertInstanceOf(User::class, $holder);
        app(MembershipService::class)->assignRole(
            $this->findProject($this->intField($setup, 'project_id')),
            $holder,
            $coordinator,
        );

        $guard = app(ManagedRoleGuard::class);
        $cases = $expected['cases'];
        $this->assertIsArray($cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $actor = $this->actor($this->stringField($case, 'actor'));
            $principal = $this->actor($this->stringField($case, 'principal'));
            $project = $this->findProject($this->intField($case, 'project_id'));
            $role = Role::query()->find($this->intField($case, 'role_id'));
            $this->assertInstanceOf(User::class, $actor);
            $this->assertInstanceOf(User::class, $principal);
            $this->assertInstanceOf(Role::class, $role);
            $error = $case['error'] ?? null;
            $this->assertTrue($error === null || is_string($error));
            try {
                $guard->assign($actor, $project, $principal, $role);
                $this->assertNull($error);
            } catch (DomainException $exception) {
                $this->assertIsString($error);
                $this->assertStringContainsString($error, $exception->getMessage());
            }
            $stored = $this->memberRoleExists((int) $project->id, (string) $principal->login, (int) $role->id);
            $this->assertSame($error === null, $stored);
        }
    }

    public function test_spent_time_visibility_matches_the_recorded_rows(): void
    {
        Redmine701Fixture::load();
        $expected = $this->recorded('expectations/identity-acl/time-entries.json');
        $project = $this->findProject($this->intField($expected, 'project_id'));
        $visibility = app(TimeEntryVisibility::class);
        $rows = $expected['rows'];
        $this->assertIsArray($rows);
        foreach ($rows as $case) {
            $this->assertIsArray($case);
            $this->assertSame($this->idList($case), $this->visibleTimeEntryIds($visibility, $case, $project));
        }

        $history = $expected['history'];
        $this->assertIsArray($history);
        $sides = app(IssueHistorySides::class);
        foreach ($history as $case) {
            $this->assertIsArray($case);
            $actor = $this->actor($this->stringField($case, 'login'));
            $issue = Issue::query()->find($this->intField($case, 'issue_id'));
            $this->assertInstanceOf(User::class, $actor);
            $this->assertInstanceOf(Issue::class, $issue);
            $side = $sides->forIssue($actor, $issue, $project);
            $this->assertSame((bool) $case['visible'], $side->spentTimeVisible);
            $ids = [];
            foreach ($side->timeEntries as $entry) {
                $ids[] = $entry->id;
            }
            $this->assertSame($this->idList($case), $ids);
        }
    }

    public function test_spent_time_visibility_uses_the_most_open_role_and_ignores_other_values(): void
    {
        Redmine701Fixture::load();
        $expected = $this->recorded('expectations/identity-acl/time-entries.json');
        $open = $expected['most_open'];
        $this->assertIsArray($open);
        $actor = $this->actor($this->stringField($open, 'login'));
        $project = $this->findProject($this->intField($open, 'project_id'));
        $role = Role::query()->find($this->intField($open, 'extra_role_id'));
        $this->assertInstanceOf(User::class, $actor);
        $this->assertInstanceOf(Role::class, $role);
        app(MembershipService::class)->assignRole($project, $actor, $role);
        $this->assertSame(
            $this->idList($open),
            $this->visibleTimeEntryIds(app(TimeEntryVisibility::class), $open, $project),
        );
    }

    public function test_spent_time_visibility_ignores_values_other_than_all_or_own(): void
    {
        Redmine701Fixture::load();
        $expected = $this->recorded('expectations/identity-acl/time-entries.json');
        $ignored = $expected['ignored_value'];
        $this->assertIsArray($ignored);
        $limited = Role::query()->find($this->intField($ignored, 'role_id'));
        $this->assertInstanceOf(Role::class, $limited);
        $limited->time_entries_visibility = $this->stringField($ignored, 'visibility');
        $limited->save();
        $limitedProject = $this->findProject($this->intField($ignored, 'project_id'));
        $this->assertSame(
            $this->idList($ignored),
            $this->visibleTimeEntryIds(app(TimeEntryVisibility::class), $ignored, $limitedProject),
        );
    }

    /**
     * @param  array<mixed>  $copy
     */
    private function assertInheritedCopy(array $copy): void
    {
        $projectId = $this->projectId($copy);
        $login = $copy['login'] ?? null;
        $roleId = $copy['role_id'] ?? null;
        $source = $copy['inherited_from'] ?? null;
        $this->assertIsString($login);
        $this->assertIsInt($roleId);
        $this->assertIsArray($source);
        $rowId = $this->memberRoleId($projectId, $login, $roleId);
        $inheritedFrom = DB::table('member_roles')->where('id', $rowId)->value('inherited_from');
        $this->assertNotNull($inheritedFrom);
        $this->assertSame($this->memberRoleId($this->projectId($source), $this->stringField($source, 'login'), $this->intField($source, 'role_id')), (int) $inheritedFrom);
    }

    /**
     * @param  array<mixed>  $row
     */
    private function projectId(array $row): int
    {
        if (isset($row['project_id'])) {
            $this->assertIsInt($row['project_id']);

            return $row['project_id'];
        }

        $identifier = $row['identifier'] ?? null;
        $this->assertIsString($identifier);
        $id = DB::table('projects')->where('identifier', $identifier)->value('id');
        $this->assertNotNull($id);

        return (int) $id;
    }

    private function memberRoleId(int $projectId, string $login, int $roleId): int
    {
        $id = DB::table('member_roles')
            ->join('members', 'members.id', '=', 'member_roles.member_id')
            ->where('members.project_id', $projectId)
            ->where('members.user_id', $this->userId($login))
            ->where('member_roles.role_id', $roleId)
            ->value('member_roles.id');
        $this->assertNotNull($id, $login.' role '.$roleId.' on project '.$projectId);

        return (int) $id;
    }

    private function userId(string $login): int
    {
        $id = DB::table('users')->where('login', $login)->value('id');
        $this->assertNotNull($id);

        return (int) $id;
    }

    private function actor(?string $login): ?User
    {
        if ($login === null) {
            return null;
        }

        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
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
     * @param  array<mixed>  $case
     */
    private function optionalTracker(array $case): ?Tracker
    {
        $trackerId = $case['tracker_id'] ?? null;
        if ($trackerId === null) {
            return null;
        }
        $this->assertIsInt($trackerId);
        $tracker = Tracker::query()->find($trackerId);
        $this->assertInstanceOf(Tracker::class, $tracker);

        return $tracker;
    }

    private function findProject(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
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
     * @param  array<mixed>  $case
     * @return list<int>
     */
    private function visibleIssueIds(IssueVisibility $visibility, array $case): array
    {
        $login = $case['login'] ?? null;
        $this->assertIsString($login);
        $ids = $visibility
            ->apply(Issue::query(), $this->actor($login), $this->findProject($this->intField($case, 'project_id')))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $list = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $list[] = (int) $id;
            }
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $case
     * @return list<int>
     */
    private function visibleTimeEntryIds(TimeEntryVisibility $visibility, array $case, Project $project): array
    {
        $actor = $this->actor($this->stringField($case, 'login'));
        $this->assertInstanceOf(User::class, $actor);
        $ids = $visibility
            ->apply(TimeEntry::query()->where('project_id', $project->id), $actor, $project)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $list = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $list[] = (int) $id;
            }
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $case
     */
    private function assertIssueWrite(IssueService $issues, array $case): void
    {
        $actor = $this->actor($this->stringField($case, 'login'));
        $issue = Issue::query()->find($this->intField($case, 'issue_id'));
        $this->assertInstanceOf(User::class, $actor);
        $this->assertInstanceOf(Issue::class, $issue);
        $error = $case['error'] ?? null;
        $this->assertTrue($error === null || is_string($error));
        try {
            $issues->update($actor, $issue, ['subject' => $this->stringField($case, 'subject')]);
            $this->assertNull($error);
        } catch (DomainException $exception) {
            $this->assertIsString($error);
            $this->assertStringContainsString($error, $exception->getMessage());
        }
        $stored = DB::table('issues')->where('id', $issue->id)->value('subject');
        $this->assertSame($this->stringField($case, 'stored_subject'), $stored);
    }

    /**
     * @param  array<mixed>  $case
     */
    private function assertIssueCreate(IssueService $issues, array $case): void
    {
        $actor = $this->actor($this->stringField($case, 'login'));
        $this->assertInstanceOf(User::class, $actor);
        $error = $case['error'] ?? null;
        $this->assertTrue($error === null || is_string($error));
        $attributes = [
            'tracker_id' => $this->intField($case, 'tracker_id'),
            'subject' => $this->stringField($case, 'subject'),
        ];
        if (array_key_exists('estimated_hours', $case)) {
            $attributes['estimated_hours'] = $case['estimated_hours'];
        }
        try {
            $issues->create($actor, $this->findProject($this->intField($case, 'project_id')), $attributes);
            $this->assertNull($error);
        } catch (DomainException $exception) {
            $this->assertIsString($error);
            $this->assertStringContainsString($error, $exception->getMessage());
        }
    }

    private function memberRoleExists(int $projectId, string $login, int $roleId): bool
    {
        return DB::table('member_roles')
            ->join('members', 'members.id', '=', 'member_roles.member_id')
            ->join('users', 'users.id', '=', 'members.user_id')
            ->where('members.project_id', $projectId)
            ->where('users.login', $login)
            ->where('member_roles.role_id', $roleId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function recorded(string $relative): array
    {
        $path = Redmine701Fixture::directory().'/'.$relative;
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
        $path = Redmine701Fixture::directory().'/expectations/identity-acl/allow-deny.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }
}
