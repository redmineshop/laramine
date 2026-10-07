<?php

namespace Tests\Parity;

use App\Domain\Issues\IssueService;
use App\Domain\Workflow\WorkflowService;
use App\Domain\WorkflowDeniedException;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares workflow transitions and field-rule merge to the shared pin.
 *
 * Close and reopen blockers are compared to blockers.json.
 */
class WorkflowParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_transitions_and_field_rules_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $workflows = app(WorkflowService::class);
        $allows = $expected['allows'];
        $rules = $expected['rules'];
        $this->assertIsArray($allows);
        $this->assertIsArray($rules);

        foreach ($allows as $case) {
            $this->assertIsArray($case);
            $login = $this->stringField($case, 'login');
            $issue = $this->issue($this->intField($case, 'issue_id'));
            $status = IssueStatus::query()->find($this->intField($case, 'status_id'));
            $allowed = $case['allowed'] ?? null;
            $this->assertInstanceOf(IssueStatus::class, $status);
            $this->assertIsBool($allowed);
            $this->assertSame(
                $allowed,
                $workflows->allowsTransition($this->actor($login), $issue, $status),
                $login.' to status '.$status->id,
            );
        }

        foreach ($rules as $case) {
            $this->assertIsArray($case);
            $rule = $case['rule'] ?? null;
            $this->assertTrue($rule === null || is_string($rule));
            $this->assertSame(
                $rule,
                $workflows->fieldRule(
                    $this->actor($this->stringField($case, 'login')),
                    $this->issue($this->intField($case, 'issue_id')),
                    $this->stringField($case, 'field'),
                ),
            );
        }
    }

    public function test_required_hours_and_observer_status_are_denied(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $clear = $expected['deny_clear_estimated_hours'];
        $status = $expected['deny_observer_status'];
        $create = $expected['member_create_without_hours'];
        $this->assertIsArray($clear);
        $this->assertIsArray($status);
        $this->assertIsArray($create);

        $this->assertDeniedUpdate($clear, []);
        $fresh = $this->issue($this->intField($clear, 'issue_id'))->fresh();
        $this->assertNotNull($fresh);
        $this->assertEqualsWithDelta($this->floatField($clear, 'estimated_hours'), (float) $fresh->estimated_hours, 0.001);

        $this->assertDeniedUpdate($status, ['status_id' => $this->intField($status, 'status_id')]);
        $this->assertSame($this->intField($status, 'stored_status_id'), (int) $this->issue($this->intField($status, 'issue_id'))->fresh()?->status_id);

        $this->assertDeniedCreate($create);
        $this->assertSame($this->intField($create, 'issue_count'), DB::table('issues')->count());
    }

    public function test_allowed_and_manager_transitions_follow_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $allow = $expected['allow_next_status'];
        $deny = $expected['deny_manager_status'];
        $this->assertIsArray($allow);
        $this->assertIsArray($deny);

        $this->assertDeniedUpdate($deny, ['status_id' => $this->intField($deny, 'status_id')]);
        $this->assertSame($this->intField($deny, 'stored_status_id'), (int) $this->issue($this->intField($deny, 'issue_id'))->fresh()?->status_id);

        $actor = $this->actor($this->stringField($allow, 'login'));
        $issue = $this->issue($this->intField($allow, 'issue_id'));
        $updated = app(IssueService::class)->update($actor, $issue, [
            'status_id' => $this->intField($allow, 'status_id'),
        ]);
        $this->assertSame($this->intField($allow, 'stored_status_id'), $updated->status_id);
    }

    public function test_create_honors_the_required_field_and_admin_bypass(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $member = $expected['member_create_with_hours'];
        $admin = $expected['admin_create_without_hours'];
        $this->assertIsArray($member);
        $this->assertIsArray($admin);
        $issues = app(IssueService::class);

        $created = $issues->create(
            $this->actor($this->stringField($member, 'login')),
            $this->project($this->intField($member, 'project_id')),
            [
                'tracker_id' => $this->intField($member, 'tracker_id'),
                'subject' => $this->stringField($member, 'subject'),
                'estimated_hours' => $member['estimated_hours'] ?? null,
            ],
        );
        $this->assertSame($this->intField($member, 'status_id'), $created->status_id);

        $skipped = $issues->create(
            $this->actor($this->stringField($admin, 'login')),
            $this->project($this->intField($admin, 'project_id')),
            [
                'tracker_id' => $this->intField($admin, 'tracker_id'),
                'subject' => $this->stringField($admin, 'subject'),
            ],
        );
        $this->assertSame($this->intField($admin, 'status_id'), $skipped->status_id);
        $this->assertNull($skipped->estimated_hours);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Workflows \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/WorkflowParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/workflows/transitions.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/workflows/blockers.json', $checklist);
    }

    public function test_close_and_reopen_blockers_match_the_recorded_steps(): void
    {
        Redmine701Fixture::load();
        $expected = $this->blockerExpectation();
        $steps = $expected['steps'];
        $this->assertIsArray($steps);
        $issues = app(IssueService::class);
        foreach ($steps as $step) {
            $this->assertIsArray($step);
            $this->assertBlockerStep($issues, $step);
        }
    }

    /**
     * @param  array<mixed>  $step
     */
    private function assertBlockerStep(IssueService $issues, array $step): void
    {
        $action = $this->stringField($step, 'action');
        $actor = $this->actor($this->stringField($step, 'login'));
        $error = $step['error'] ?? null;
        $this->assertTrue($error === null || is_string($error));
        try {
            if ($action === 'create') {
                $issues->create($actor, $this->project($this->intField($step, 'project_id')), [
                    'tracker_id' => $this->intField($step, 'tracker_id'),
                    'parent_id' => $this->intField($step, 'parent_id'),
                    'subject' => $this->stringField($step, 'subject'),
                ]);
            } else {
                $issue = $this->issue($this->intField($step, 'issue_id'));
                $attributes = $action === 'attach'
                    ? ['parent_id' => $this->intField($step, 'parent_id')]
                    : ['status_id' => $this->intField($step, 'status_id')];
                $issues->update($actor, $issue, $attributes);
            }
            $this->assertNull($error, $action);
        } catch (WorkflowDeniedException $exception) {
            $this->assertIsString($error, $action);
            $this->assertStringContainsString($error, $exception->getMessage(), $action);
        }

        if ($action === 'create') {
            $this->assertSame($this->intField($step, 'issue_count'), DB::table('issues')->count());

            return;
        }

        $issueId = $this->intField($step, 'issue_id');
        if ($action === 'attach') {
            $parentId = DB::table('issues')->where('id', $issueId)->value('parent_id');
            $expectedParent = $step['stored_parent_id'] ?? null;
            $this->assertTrue($expectedParent === null || is_int($expectedParent));
            $this->assertSame($expectedParent, $parentId === null ? null : (int) $parentId);

            return;
        }

        $this->assertSame($this->intField($step, 'stored_status_id'), (int) DB::table('issues')->where('id', $issueId)->value('status_id'));
    }

    /**
     * @param  array<mixed>  $case
     * @param  array<string, mixed>  $attributes
     */
    private function assertDeniedUpdate(array $case, array $attributes): void
    {
        if (! array_key_exists('estimated_hours', $attributes) && array_key_exists('estimated_hours', $case) && ! isset($attributes['status_id'])) {
            $attributes['estimated_hours'] = null;
        }
        try {
            app(IssueService::class)->update(
                $this->actor($this->stringField($case, 'login')),
                $this->issue($this->intField($case, 'issue_id')),
                $attributes,
            );
            $this->fail($this->stringField($case, 'message'));
        } catch (WorkflowDeniedException $exception) {
            $this->assertStringContainsString($this->stringField($case, 'message'), $exception->getMessage());
        }
    }

    /**
     * @param  array<mixed>  $case
     */
    private function assertDeniedCreate(array $case): void
    {
        try {
            app(IssueService::class)->create(
                $this->actor($this->stringField($case, 'login')),
                $this->project($this->intField($case, 'project_id')),
                [
                    'tracker_id' => $this->intField($case, 'tracker_id'),
                    'subject' => $this->stringField($case, 'subject'),
                ],
            );
            $this->fail($this->stringField($case, 'message'));
        } catch (WorkflowDeniedException $exception) {
            $this->assertStringContainsString($this->stringField($case, 'message'), $exception->getMessage());
        }
    }

    private function actor(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function issue(int $id): Issue
    {
        $issue = Issue::query()->find($id);
        $this->assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    private function project(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
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
     */
    private function floatField(array $row, string $key): float
    {
        $value = $row[$key] ?? null;
        $this->assertTrue(is_int($value) || is_float($value));

        return (float) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function blockerExpectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/workflows/blockers.json';
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
        $path = Redmine701Fixture::directory().'/expectations/workflows/transitions.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }
}
