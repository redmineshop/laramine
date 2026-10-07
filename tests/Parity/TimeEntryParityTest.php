<?php

namespace Tests\Parity;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Queries\SavedQueryService;
use App\Domain\TimeEntries\IssueSpentHours;
use App\Domain\TimeEntries\TimeEntryQueryRunner;
use App\Domain\TimeEntries\TimeEntryService;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares time-entry writes, rollup, and TimeEntryQuery results to the shared pin.
 */
class TimeEntryParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_match_the_recorded_rows(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('time-entries/writes.json');
        $entries = app(TimeEntryService::class);
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $project = Project::query()->findOrFail(1);

        $clock = $expected['clock'];
        $this->assertIsArray($clock);
        $created = $entries->create($ada, $project, [
            'activity_id' => $clock['activity_id'],
            'hours' => $clock['hours'],
            'spent_on' => $clock['spent_on'],
            'issue_id' => $clock['issue_id'],
            'comments' => $clock['comments'],
        ]);
        $this->assertSame($clock['row'], $this->snapshot($created));
        $entries->delete($admin, $created);

        foreach ($this->listField($expected, 'parses') as $parse) {
            $row = $entries->create($ada, $project, $this->base(['hours' => $parse['hours'], 'comments' => 'Parse']));
            $this->assertSame($parse['stored'], sprintf('%.4f', (float) $row->hours), (string) $parse['hours']);
            $entries->delete($admin, $row);
        }
        foreach ($this->listField($expected, 'reject_hours') as $reject) {
            $this->rejected(
                fn () => $entries->create($ada, $project, $this->base(['hours' => $reject['hours']])),
                $this->stringField($reject, 'message'),
            );
        }

        $deny = $expected['deny_log'];
        $this->assertIsArray($deny);
        $this->denied(
            fn () => $entries->create($this->user($this->stringField($deny, 'login')), $project, $this->base()),
            $this->stringField($deny, 'permission'),
        );

        foreach (['closed', 'archived'] as $gate) {
            $spec = $expected[$gate];
            $this->assertIsArray($spec);
            $project->status = $this->intField($spec, 'status');
            $project->save();
            foreach ($this->stringList($spec, 'logins') as $login) {
                $this->denied(
                    fn () => $entries->create($this->user($login), $project, $this->base()),
                    $this->stringField($spec, 'permission'),
                );
            }
            $project->status = Project::STATUS_ACTIVE;
            $project->save();
        }

        $other = $expected['other_project'];
        $this->assertIsArray($other);
        $this->rejected(
            fn () => $entries->create($ada, $project, $this->base(['issue_id' => $other['issue_id']])),
            $this->stringField($other, 'message'),
        );

        $missing = $this->base();
        unset($missing['activity_id']);
        $missingActivity = $expected['missing_activity'];
        $this->assertIsArray($missingActivity);
        $omitted = $entries->create($ada, $project, $missing);
        $this->assertSame($this->intField($missingActivity, 'activity_id'), (int) $omitted->activity_id);
        $entries->delete($admin, $omitted);

        $inactive = $expected['inactive_activity'];
        $this->assertIsArray($inactive);
        $child = Enumeration::query()->create([
            'name' => 'Development override',
            'type' => 'TimeEntryActivity',
            'active' => false,
            'is_default' => false,
            'position' => 2,
            'parent_id' => 3,
            'project_id' => 1,
        ]);
        $this->rejected(
            fn () => $entries->create($ada, $project, $this->base(['activity_id' => 3])),
            $this->stringField($inactive, 'message'),
        );
        $this->rejected(
            fn () => $entries->create($ada, $project, $this->base(['activity_id' => $child->id])),
            $this->stringField($inactive, 'message'),
        );
        $child->active = true;
        $child->save();
        $override = $entries->create($ada, $project, $this->base(['activity_id' => $child->id, 'comments' => 'Override']));
        $this->assertSame((int) $child->id, (int) $override->activity_id);
        $entries->delete($admin, $override);
        $this->rejected(
            fn () => $entries->create($ada, $project, $this->base(['activity_id' => 3])),
            $this->stringField($inactive, 'message'),
        );
        $child->delete();

        $messages = $expected['required_messages'];
        $this->assertIsArray($messages);
        foreach ([$this->stringField($expected, 'required_json'), $this->stringField($expected, 'required_yaml')] as $stored) {
            $this->setting('timelog_required_fields', $stored);
            $withoutIssue = $this->base();
            unset($withoutIssue['issue_id']);
            $this->rejected(
                fn () => $entries->create($ada, $project, $withoutIssue),
                $this->stringField($messages, 'issue_id'),
            );
            $this->rejected(
                fn () => $entries->create($ada, $project, $this->base(['comments' => ''])),
                $this->stringField($messages, 'comments'),
            );
        }
        Setting::query()->where('name', 'timelog_required_fields')->delete();

        $custom = $expected['custom_field'];
        $this->assertIsArray($custom);
        $before = TimeEntry::query()->count();
        $field = app(CustomFieldService::class)->save([
            'type' => 'TimeEntryCustomField',
            'name' => $this->stringField($custom, 'name'),
            'field_format' => 'string',
            'is_required' => true,
            'is_filter' => true,
            'visible' => true,
        ]);
        $this->rejected(
            fn () => $entries->create($ada, $project, $this->base(['comments' => 'Need billing'])),
            $this->stringField($custom, 'message'),
        );
        $this->assertSame($before, TimeEntry::query()->count());
        $billed = $entries->create($ada, $project, $this->base([
            'comments' => 'Billed',
            'custom_field_values' => [$field->id => $custom['value']],
        ]));
        $this->assertSame(
            $this->stringField($custom, 'customized_type'),
            CustomValue::query()->where('custom_field_id', $field->id)->value('customized_type'),
        );
        $this->assertSame(
            $this->stringField($custom, 'value'),
            CustomValue::query()->where('custom_field_id', $field->id)->value('value'),
        );
        $field->is_required = false;
        $field->save();

        $editDenied = $expected['edit_denied'];
        $this->assertIsArray($editDenied);
        $entry1 = TimeEntry::query()->findOrFail(1);
        $this->denied(
            fn () => $entries->update($ada, $entry1, ['comments' => $expected['own_comment']]),
            $this->stringField($editDenied, 'permission'),
        );
        $this->grant(1, 'edit_own_time_entries');
        $entry1 = $entries->update($ada, $entry1, ['comments' => $this->stringField($expected, 'own_comment')]);
        $this->assertSame($this->stringField($expected, 'own_comment'), (string) $entry1->comments);

        $entry2 = TimeEntry::query()->findOrFail(2);
        $this->denied(
            fn () => $entries->update($ada, $entry2, ['comments' => 'nope']),
            $this->stringField($editDenied, 'permission'),
        );
        $entry5 = TimeEntry::query()->findOrFail(5);
        $this->denied(
            fn () => $entries->update($this->user('finn'), $entry5, ['comments' => 'nope']),
            $this->stringField($editDenied, 'permission'),
        );
        $bea = $this->user('bea');
        $entry2 = $entries->update($bea, $entry2, ['comments' => $expected['manager_comment']]);
        $this->assertSame($this->stringField($expected, 'manager_comment'), (string) $entry2->comments);
        $this->denied(
            fn () => $entries->update($bea, $entry2, ['user_id' => 1]),
            $this->stringField($expected, 'reassign_permission'),
        );

        $entries->delete($ada, $entry1);
        $entries->delete($ada, $billed);
        $this->assertNull(CustomValue::query()->where('custom_field_id', $field->id)->first());
        $this->assertSame($this->listField($expected, 'remaining'), $this->remaining());
    }

    public function test_rollup_matches_the_recorded_hours(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('time-entries/rollup.json');
        $hours = app(IssueSpentHours::class);
        $before = [];
        foreach ($this->listField($expected, 'before') as $row) {
            $issue = Issue::query()->findOrFail($this->intField($row, 'issue_id'));
            $before[] = [
                'issue_id' => (int) $issue->id,
                'spent' => $this->money($hours->spent($issue)),
                'total' => $this->money($hours->total($issue)),
            ];
        }
        $this->assertSame($this->listField($expected, 'before'), $before);

        $after = $expected['after_child_hour'];
        $this->assertIsArray($after);
        app(TimeEntryService::class)->create($this->user('ada'), Project::query()->findOrFail(1), [
            'activity_id' => $after['activity_id'],
            'hours' => $after['hours'],
            'spent_on' => $after['spent_on'],
            'issue_id' => $after['issue_id'],
            'comments' => 'Child hour',
        ]);
        $parent = Issue::query()->findOrFail($this->intField($after, 'parent_issue_id'));
        $child = Issue::query()->findOrFail($this->intField($after, 'issue_id'));
        $this->assertSame($this->stringField($after, 'spent_parent'), $this->money($hours->spent($parent)));
        $this->assertSame($this->stringField($after, 'total_parent'), $this->money($hours->total($parent)));
        $this->assertSame($this->stringField($after, 'spent_child'), $this->money($hours->spent($child)));
        $this->assertSame($this->stringField($after, 'total_child'), $this->money($hours->total($child)));
    }

    public function test_list_report_and_csv_match_the_recorded_rows(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('time-entries/query.json');
        Carbon::setTestNow($this->stringField($expected, 'anchor'));
        try {
            $runner = app(TimeEntryQueryRunner::class);
            $ada = $this->user('ada');
            $this->assertSame($this->listField($expected, 'ada_rows'), $runner->rows($ada, []));
            foreach ($this->listField($expected, 'lists') as $list) {
                $this->assertSame(
                    $this->intList($list, 'ids'),
                    $this->ids($runner->rows($this->user($this->stringField($list, 'login')), $this->source($list))),
                    $this->stringField($list, 'name'),
                );
            }
            $this->assertSame($this->stringField($expected, 'csv_minutes'), $runner->csv($ada, []));

            $this->setting('timespan_format', 'decimal');
            $this->assertSame($this->stringField($expected, 'csv_decimal'), $runner->csv($ada, []));

            foreach ($this->listField($expected, 'reports') as $report) {
                $this->timespan($this->stringField($report, 'format'));
                $actual = $runner->report($this->user($this->stringField($report, 'login')), [
                    'filters' => [],
                    'options' => $report['options'],
                ]);
                $this->assertSame($this->stringList($report, 'periods'), $actual['periods'], $this->stringField($report, 'name'));
                $this->assertSame($this->listField($report, 'rows'), $actual['rows'], $this->stringField($report, 'name'));
                $this->assertSame($report['totals'], $actual['totals'], $this->stringField($report, 'name'));
            }

            $this->timespan('minutes');
            $saved = $expected['saved'];
            $this->assertIsArray($saved);
            $query = app(SavedQueryService::class)->create($ada, [
                'type' => 'TimeEntryQuery',
                'name' => $saved['name'],
                'project_id' => $saved['project_id'],
                'filters' => $saved['filters'],
                'column_names' => $saved['column_names'],
                'sort_criteria' => $saved['sort_criteria'],
            ]);
            $this->assertSame([$saved['row']], $runner->rows($ada, $query));
            $this->assertSame($this->stringField($saved, 'csv'), $runner->csv($ada, $query));

            try {
                app(SavedQueryService::class)->create($ada, [
                    'type' => 'TimeEntryQuery',
                    'name' => 'Grouped',
                    'group_by' => 'user',
                ]);
                $this->fail('Time entry group_by was accepted.');
            } catch (QueryValidationException $exception) {
                $this->assertSame($this->stringField($expected, 'group_by_message'), $exception->getMessage());
            }

            $archived = $expected['archived_project'];
            $this->assertIsArray($archived);
            $childProject = Project::query()->findOrFail($this->intField($archived, 'project_id'));
            $childProject->status = $this->intField($archived, 'status');
            $childProject->save();
            $this->assertSame(
                $this->intList($archived, 'ids'),
                $this->ids($runner->rows($this->user($this->stringField($archived, 'login')), [])),
            );
            $childProject->status = Project::STATUS_ACTIVE;
            $childProject->save();

            $private = $expected['private_issue'];
            $this->assertIsArray($private);
            app(TimeEntryService::class)->create($this->user($this->stringField($private, 'login')), Project::query()->findOrFail(1), [
                'activity_id' => 3,
                'hours' => $private['hours'],
                'spent_on' => '2026-08-27',
                'issue_id' => $private['issue_id'],
                'comments' => 'Private',
            ]);
            $filter = ['filters' => ['issue_id' => ['operator' => '=', 'values' => [(string) $private['issue_id']]]]];
            $adaPrivate = $runner->rows($ada, $filter);
            $beaPrivate = $runner->rows($this->user('bea'), $filter);
            $this->assertSame($this->stringField($private, 'ada'), $adaPrivate[0]['issue'] ?? null);
            $this->assertSame($this->stringField($private, 'bea'), $beaPrivate[0]['issue'] ?? null);

            $field = app(CustomFieldService::class)->save([
                'type' => 'TimeEntryCustomField',
                'name' => 'Billing code',
                'field_format' => 'string',
                'is_filter' => true,
                'visible' => true,
            ]);
            $custom = $expected['custom_field'];
            $this->assertIsArray($custom);
            CustomValue::query()->create([
                'customized_type' => 'TimeEntry',
                'customized_id' => 1,
                'custom_field_id' => $field->id,
                'value' => $custom['value'],
            ]);
            $this->assertSame($this->intList($custom, 'ids'), $this->ids($runner->rows($ada, [
                'filters' => ['cf_'.$field->id => ['operator' => '=', 'values' => [$this->stringField($custom, 'value')]]],
            ])));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_default_activity_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('time-entries/activity.json');
        $entries = app(TimeEntryService::class);
        $admin = $this->user('admin');
        $project = Project::query()->findOrFail(1);
        $omit = $expected['omit'];
        $this->assertIsArray($omit);
        $blank = $this->base();
        unset($blank['activity_id']);
        $created = $entries->create($this->user($this->stringField($omit, 'login')), $project, $blank);
        $this->assertSame($this->intField($omit, 'activity_id'), (int) $created->activity_id);
        $entries->delete($admin, $created);

        foreach ($this->listField($expected, 'cases') as $case) {
            $this->resetActivityPin();
            $keys = $this->activityKeys($case);
            $this->activityRoles($case, $project, $keys);
            $login = $this->stringField($case, 'login');
            if (($case['create_user'] ?? false) === true) {
                User::factory()->create(['login' => $login]);
            }
            $this->activityGroup($case, $project, $keys, $login);
            $actor = $this->user($login);
            $attributes = $this->base();
            unset($attributes['activity_id']);
            $error = $case['error'] ?? null;
            if (is_string($error)) {
                $this->rejected(fn () => $entries->create($actor, $project, $attributes), $error);

                continue;
            }
            $row = $entries->create($actor, $project, $attributes);
            $expectedId = $this->expectedActivityId($case, $keys);
            $this->assertSame($expectedId, (int) $row->activity_id, $this->stringField($case, 'name'));
            $entries->delete($admin, $row);
        }
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Time entries and attachments \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/TimeEntryParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/time-entries/writes.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/time-entries/rollup.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/time-entries/query.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/time-entries/activity.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function base(array $override = []): array
    {
        return array_merge([
            'activity_id' => 3,
            'hours' => '1',
            'spent_on' => '2026-08-27',
            'issue_id' => 1,
            'comments' => 'Row',
        ], $override);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TimeEntry $entry): array
    {
        $spent = $entry->getRawOriginal('spent_on');

        return [
            'project_id' => (int) $entry->project_id,
            'issue_id' => (int) $entry->issue_id,
            'user_id' => (int) $entry->user_id,
            'author_id' => (int) $entry->author_id,
            'activity_id' => (int) $entry->activity_id,
            'hours' => sprintf('%.4f', (float) $entry->hours),
            'comments' => $entry->comments,
            'spent_on' => is_string($spent) ? substr($spent, 0, 10) : '',
            'tyear' => (int) $entry->tyear,
            'tmonth' => (int) $entry->tmonth,
            'tweek' => (int) $entry->tweek,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function remaining(): array
    {
        $rows = [];
        foreach (TimeEntry::query()->orderBy('id')->get() as $entry) {
            $rows[] = ['id' => (int) $entry->id] + $this->snapshot($entry);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $case
     * @return array<string, mixed>
     */
    private function source(array $case): array
    {
        $source = ['filters' => is_array($case['filters'] ?? null) ? $case['filters'] : []];
        if (isset($case['sort_criteria'])) {
            $source['sort_criteria'] = $case['sort_criteria'];
        }

        return $source;
    }

    private function timespan(string $format): void
    {
        if ($format === 'decimal') {
            $this->setting('timespan_format', 'decimal');

            return;
        }
        Setting::query()->where('name', 'timespan_format')->delete();
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
    }

    private function grant(int $roleId, string $permission): void
    {
        $role = Role::query()->findOrFail($roleId);
        $permissions = $role->permissions;
        $this->assertIsArray($permissions);
        $permissions[] = $permission;
        $role->permissions = array_values(array_unique($permissions));
        $role->save();
    }

    private function money(float $hours): string
    {
        return number_format($hours, 2, '.', '');
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user, $login);

        return $user;
    }

    private function rejected(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail($message);
        } catch (PermissionDeniedException $denied) {
            $this->fail($denied->permission);
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function denied(callable $action, string $permission): void
    {
        try {
            $action();
            $this->fail($permission);
        } catch (PermissionDeniedException $denied) {
            $this->assertSame($permission, $denied->permission);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(string $name): array
    {
        $path = dirname(__DIR__).'/Parity/fixtures/redmine-7.0.1/expectations/'.$name;
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<array<mixed>>
     */
    private function listField(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value, $key);

        return array_is_list($value) ? $value : [];
    }

    /**
     * @param  array<mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $this->assertIsString($value, $key);

        return $value;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->assertIsInt($value, $key);

        return $value;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<int>
     */
    private function intList(array $row, string $key): array
    {
        $ids = [];
        foreach ($this->listField($row, $key) as $id) {
            $this->assertIsInt($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function resetActivityPin(): void
    {
        Role::query()->whereIn('id', [1, 2])->update(['default_time_entry_activity_id' => 3]);
        $extra = Role::query()->where('name', 'like', 'parity-activity-%')->pluck('id');
        if ($extra->isNotEmpty()) {
            $memberRoleIds = MemberRole::query()->whereIn('role_id', $extra->all())->pluck('id');
            if ($memberRoleIds->isNotEmpty()) {
                MemberRole::query()->whereIn('inherited_from', $memberRoleIds->all())->update(['inherited_from' => null]);
                MemberRole::query()->whereIn('id', $memberRoleIds->all())->update(['inherited_from' => null]);
                MemberRole::query()->whereIn('id', $memberRoleIds->all())->delete();
            }
            Role::query()->whereIn('id', $extra->all())->update(['default_time_entry_activity_id' => null]);
            Role::query()->whereIn('id', $extra->all())->delete();
        }
        Enumeration::query()->where('type', 'TimeEntryActivity')->where('id', '!=', 3)->update(['parent_id' => null]);
        Enumeration::query()->where('type', 'TimeEntryActivity')->where('id', '!=', 3)->delete();
        Enumeration::query()->whereKey(3)->update(['active' => true, 'is_default' => true]);
    }

    /**
     * @param  array<mixed>  $case
     * @return array<string, int>
     */
    private function activityKeys(array $case): array
    {
        if (isset($case['hide']) && is_array($case['hide'])) {
            foreach ($case['hide'] as $id) {
                $this->assertIsInt($id);
                Enumeration::query()->whereKey($id)->update(['active' => false]);
            }
        }

        $keys = [];
        if (! isset($case['activities'])) {
            return $keys;
        }
        $this->assertIsArray($case['activities']);
        foreach ($case['activities'] as $spec) {
            $this->assertIsArray($spec);
            $parent = null;
            if (isset($spec['parent']) && is_string($spec['parent'])) {
                $parent = $keys[$spec['parent']] ?? null;
                $this->assertIsInt($parent);
            } elseif (isset($spec['parent_id']) && is_int($spec['parent_id'])) {
                $parent = $spec['parent_id'];
            }
            $projectId = array_key_exists('project_id', $spec) ? $spec['project_id'] : null;
            $this->assertTrue($projectId === null || is_int($projectId));
            $row = Enumeration::query()->create([
                'name' => $this->stringField($spec, 'name'),
                'type' => 'TimeEntryActivity',
                'active' => ($spec['active'] ?? true) === true,
                'is_default' => ($spec['is_default'] ?? false) === true,
                'position' => $this->intField($spec, 'position'),
                'parent_id' => $parent,
                'project_id' => $projectId,
            ]);
            $keys[$this->stringField($spec, 'key')] = (int) $row->id;
        }
        if (isset($case['role_defaults']) && is_array($case['role_defaults'])) {
            foreach ($case['role_defaults'] as $spec) {
                $this->assertIsArray($spec);
                $activityId = null;
                if (isset($spec['activity']) && is_string($spec['activity'])) {
                    $activityId = $keys[$spec['activity']] ?? null;
                    $this->assertIsInt($activityId);
                } elseif (array_key_exists('activity_id', $spec)) {
                    $this->assertTrue($spec['activity_id'] === null || is_int($spec['activity_id']));
                    $activityId = $spec['activity_id'];
                }
                Role::query()->whereKey($this->intField($spec, 'role_id'))->update([
                    'default_time_entry_activity_id' => $activityId,
                ]);
            }
        }

        return $keys;
    }

    /**
     * @param  array<mixed>  $case
     * @param  array<string, int>  $keys
     */
    private function activityRoles(array $case, Project $project, array $keys): void
    {
        if (! isset($case['extra_roles']) || ! is_array($case['extra_roles'])) {
            return;
        }
        $memberships = app(MembershipService::class);
        foreach ($case['extra_roles'] as $spec) {
            $this->assertIsArray($spec);
            $activity = $keys[$this->stringField($spec, 'activity')] ?? null;
            $this->assertIsInt($activity);
            $role = Role::query()->create([
                'name' => $this->stringField($spec, 'name'),
                'builtin' => $this->intField($spec, 'builtin'),
                'position' => $this->intField($spec, 'position'),
                'assignable' => true,
                'permissions' => ['log_time', 'view_issues'],
                'issues_visibility' => 'default',
                'time_entries_visibility' => 'all',
                'default_time_entry_activity_id' => $activity,
            ]);
            $user = $this->user($this->stringField($spec, 'login'));
            if (($spec['direct'] ?? false) === true) {
                $member = Member::query()->where('user_id', $user->id)->where('project_id', $project->id)->first();
                $this->assertInstanceOf(Member::class, $member);
                MemberRole::query()->create([
                    'member_id' => $member->id,
                    'role_id' => $role->id,
                    'inherited_from' => null,
                ]);

                continue;
            }
            $memberships->assignRole($project, $user, $role);
        }
    }

    /**
     * @param  array<mixed>  $case
     * @param  array<string, int>  $keys
     */
    private function activityGroup(array $case, Project $project, array $keys, string $login): void
    {
        if (! isset($case['group']) || ! is_array($case['group'])) {
            return;
        }
        $spec = $case['group'];
        $activity = $keys[$this->stringField($spec, 'activity')] ?? null;
        $this->assertIsInt($activity);
        $group = User::factory()->create([
            'login' => 'parity-activity-group',
            'type' => User::TYPE_GROUP,
            'firstname' => 'Parity',
            'lastname' => 'Group',
        ]);
        $role = Role::query()->create([
            'name' => 'parity-activity-group-role',
            'builtin' => 0,
            'position' => 12,
            'assignable' => true,
            'permissions' => ['log_time', 'view_issues'],
            'issues_visibility' => 'default',
            'time_entries_visibility' => 'all',
            'default_time_entry_activity_id' => $activity,
        ]);
        $memberships = app(MembershipService::class);
        $memberships->addUserToGroup($group, $this->user($login));
        $memberships->assignRole($project, $group, $role);
    }

    /**
     * @param  array<mixed>  $case
     * @param  array<string, int>  $keys
     */
    private function expectedActivityId(array $case, array $keys): int
    {
        if (isset($case['expected_id']) && is_int($case['expected_id'])) {
            return $case['expected_id'];
        }
        $id = $keys[$this->stringField($case, 'expected')] ?? null;
        $this->assertIsInt($id);

        return $id;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<string>
     */
    private function stringList(array $row, string $key): array
    {
        $items = [];
        foreach ($this->listField($row, $key) as $item) {
            $this->assertIsString($item);
            $items[] = $item;
        }

        return $items;
    }
}
