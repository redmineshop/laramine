<?php

namespace Tests\Parity;

use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\UserVisibility;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares membership allow/deny, users_visibility, and inherit_members
 * to the shared pin.
 *
 * Per-tracker masks, managed roles, time-entry visibility, and the user
 * directory are not part of this comparison.
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
        $this->assertDoesNotMatchRegularExpression(
            '/^\| (?!P0 table and column layout \|)(?!Users and authentication \|)(?!Identity, membership, and permissions \|)(?!Projects and issue nested sets \|)(?!Workflows \|)[^|\n]+\| VERIFIED \|/m',
            $checklist,
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
