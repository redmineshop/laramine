<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\DomainException;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryVisibility;
use App\Domain\Queries\SavedQueryService;
use App\Domain\TimeEntries\TimeEntryService;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * Time entry create, update, and delete.
 *
 * A created row is included in the existing IssueQuery `spent_hours` total.
 * That total's visibility stays with the query depth slice. This is Laramine
 * behavior on MySQL. It does not compare rows with the shared pin.
 * That comparison is `tests/Parity/TimeEntryParityTest.php`.
 */
class TimeEntryWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_stores_a_time_entry_and_visible_spent_hours(): void
    {
        $world = $this->world('time-create');
        $actor = User::factory()->create([
            'login' => 'ada',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
        ]);
        $this->grant($world->project, $actor, 'logger', [
            'view_issues',
            'view_time_entries',
            'log_time',
            'save_queries',
        ]);
        $activity = $this->activity();
        $issue = $this->issue($world, $actor, 'Logged');
        DB::table('issues')->where('id', $issue->id)->update(['updated_on' => '2020-01-02 00:00:00']);

        $entry = app(TimeEntryService::class)->create($actor, $world->project, [
            'activity_id' => $activity->id,
            'issue_id' => $issue->id,
            'hours' => '2',
            'spent_on' => '2026-10-01',
            'comments' => 'Ada work',
        ]);

        $stored = TimeEntry::query()->find($entry->id);
        $this->assertInstanceOf(TimeEntry::class, $stored);
        $this->assertSame($world->project->id, (int) $stored->project_id);
        $this->assertSame($issue->id, (int) $stored->issue_id);
        $this->assertSame($actor->id, (int) $stored->user_id);
        $this->assertSame($actor->id, (int) $stored->author_id);
        $this->assertSame($activity->id, (int) $stored->activity_id);
        $this->assertEqualsWithDelta(2.0, (float) $stored->hours, 0.001);
        $this->assertSame('Ada work', $stored->comments);
        $this->assertSame('2026-10-01', substr((string) $stored->getRawOriginal('spent_on'), 0, 10));
        $this->assertSame(2026, (int) $stored->tyear);
        $this->assertSame(10, (int) $stored->tmonth);
        $this->assertSame(40, (int) $stored->tweek);
        $this->assertSame(
            '2020-01-02 00:00:00',
            (string) DB::table('issues')->where('id', $issue->id)->value('updated_on'),
        );

        $show = app(IssueHistoryPresenter::class)->present($actor, $issue->fresh() ?? $issue);
        $this->assertContains(IssueHistoryPresenter::TAB_SPENT_TIME, $show->historyTabLabels);
        $this->assertCount(1, $show->timeEntries);
        $this->assertSame('2026-10-01', $show->timeEntries[0]->spentOn);
        $this->assertSame(2.0, $show->timeEntries[0]->hours);
        $this->assertSame('Ada work', $show->timeEntries[0]->comments);
        $this->assertSame('Development', $show->timeEntries[0]->activityName);
        $this->assertSame('Ada Lovelace', $show->timeEntries[0]->userName);

        $saved = app(SavedQueryService::class)->create($actor, [
            'name' => 'Spent',
            'project_id' => $world->project->id,
            'visibility' => QueryVisibility::PRIVATE,
            'filters' => ['status_id' => ['operator' => '*', 'values' => []]],
            'options' => ['totalable_names' => ['spent_hours']],
        ]);
        $this->assertSame(['spent_hours' => '2'], app(IssueQueryRunner::class)->totals($actor, $saved));
    }

    public function test_create_rejects_unauthorized_paths(): void
    {
        $world = $this->world('time-deny');
        $activity = $this->activity();
        $owner = User::factory()->create(['login' => 'owner']);
        $this->grant($world->project, $owner, 'owner_log', ['log_time', 'view_issues']);
        $issue = $this->issue($world, $owner, 'Denied');
        $entries = app(TimeEntryService::class);
        $payload = [
            'activity_id' => $activity->id,
            'issue_id' => $issue->id,
            'hours' => 1,
            'spent_on' => '2026-10-01',
        ];

        $stranger = User::factory()->create(['login' => 'stranger']);
        $this->expectDenied(fn () => $entries->create($stranger, $world->project, $payload), 'log_time');

        $inactive = User::factory()->create(['login' => 'inactive', 'status' => 0]);
        $this->grant($world->project, $inactive, 'inactive_log', ['log_time']);
        $this->expectDenied(fn () => $entries->create($inactive, $world->project, $payload), 'log_time');

        $this->expectDenied(
            fn () => $entries->create($owner, $world->project, [...$payload, 'user_id' => $stranger->id]),
            'log_time_for_other_users',
        );

        app(ProjectService::class)->disableModule($world->project, 'time_tracking');
        $this->expectDenied(fn () => $entries->create($owner, $world->project, $payload), 'log_time');
        $this->assertSame(0, TimeEntry::query()->count());

        $admin = User::factory()->create(['admin' => true, 'login' => 'time-admin']);
        $created = $entries->create($admin, $world->project, $payload);
        $this->assertSame($admin->id, (int) $created->author_id);
        $this->assertSame($admin->id, (int) $created->user_id);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_create_rejects_invalid_fields_and_accepts_a_project_activity(): void
    {
        $world = $this->world('time-invalid');
        $actor = User::factory()->create(['login' => 'logger']);
        $other = User::factory()->create(['login' => 'other']);
        $this->grant($world->project, $actor, 'logger', [
            'log_time',
            'log_time_for_other_users',
            'view_issues',
        ]);
        $issue = $this->issue($world, $actor, 'Valid');
        $entries = app(TimeEntryService::class);
        $system = $this->activity('Development');
        $base = [
            'activity_id' => $system->id,
            'issue_id' => $issue->id,
            'hours' => 1,
            'spent_on' => '2026-10-01',
            'comments' => 'ok',
        ];

        $this->expectInvalid(fn () => $entries->create($actor, $world->project, [...$base, 'hours' => 0]), 'Hours must be a number greater than zero.');
        $this->expectInvalid(fn () => $entries->create($actor, $world->project, [...$base, 'hours' => -1]), 'Hours must be a number greater than zero.');
        $this->expectInvalid(fn () => $entries->create($actor, $world->project, [...$base, 'hours' => 'no']), 'Hours must be a number greater than zero.');
        $this->expectInvalid(fn () => $entries->create($actor, $world->project, [...$base, 'spent_on' => '2026-02-31']), 'Spent on must be a date.');
        $this->expectInvalid(fn () => $entries->create($actor, $world->project, [...$base, 'spent_on' => 'yesterday']), 'Spent on must be a date.');
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'comments' => str_repeat('a', 1025)]),
            'Comments cannot be longer than 1024 characters.',
        );
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'comments' => 12]),
            'Comments must be text.',
        );
        $logged = $entries->create($actor, $world->project, [...$base, 'activity_id' => null]);
        $this->assertSame($system->id, (int) $logged->activity_id);
        $second = $this->activity('Support', ['position' => 2]);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'activity_id' => null]),
            'Activity is not a time entry activity.',
        );
        $second->delete();

        $priority = Enumeration::query()->create([
            'name' => 'High',
            'type' => 'IssuePriority',
            'active' => true,
            'position' => 2,
        ]);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'activity_id' => $priority->id]),
            'Activity is not a time entry activity.',
        );
        $inactive = $this->activity('Inactive', ['active' => false, 'position' => 3]);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'activity_id' => $inactive->id]),
            'Activity is not available on this project.',
        );

        $elsewhere = app(ProjectService::class)->create([
            'name' => 'Elsewhere',
            'identifier' => 'time-elsewhere',
            'is_public' => true,
        ]);
        $foreign = $this->issue($world, $actor, 'Foreign', $elsewhere);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'issue_id' => $foreign->id]),
            'Issue is not in this project.',
        );
        $foreignActivity = $this->activity('Foreign', ['project_id' => $elsewhere->id, 'position' => 4]);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'activity_id' => $foreignActivity->id]),
            'Activity is not available on this project.',
        );

        $locked = User::factory()->create(['login' => 'locked', 'status' => 0]);
        $group = User::factory()->create(['login' => 'crew', 'type' => User::TYPE_GROUP]);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'user_id' => $locked->id]),
            'Time entry user must be an active user.',
        );
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'user_id' => $group->id]),
            'Time entry user must be an active user.',
        );

        $override = $this->activity('Development', [
            'parent_id' => $system->id,
            'project_id' => $world->project->id,
            'position' => 5,
        ]);
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, $base),
            'Activity is not available on this project.',
        );
        $this->assertSame(0, TimeEntry::query()->count());

        $logged = $entries->create($actor, $world->project, [
            ...$base,
            'activity_id' => $override->id,
            'user_id' => $other->id,
            'comments' => '   ',
            'hours' => 2.5,
        ]);
        $this->assertSame($other->id, (int) $logged->user_id);
        $this->assertSame($actor->id, (int) $logged->author_id);
        $this->assertSame($override->id, (int) $logged->activity_id);
        $this->assertNull($logged->comments);
        $this->assertEqualsWithDelta(2.5, (float) $logged->hours, 0.001);

        $long = $entries->create($actor, $world->project, [
            ...$base,
            'activity_id' => $override->id,
            'issue_id' => null,
            'comments' => str_repeat('b', 1024),
        ]);
        $this->assertNull($long->issue_id);
        $this->assertSame(1024, mb_strlen((string) $long->comments));

        $override->active = false;
        $override->save();
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'activity_id' => $override->id]),
            'Activity is not available on this project.',
        );
        $this->expectInvalid(
            fn () => $entries->create($actor, $world->project, [...$base, 'activity_id' => $system->id]),
            'Activity is not available on this project.',
        );
    }

    public function test_update_and_delete_follow_edit_permissions(): void
    {
        $world = $this->world('time-edit');
        $activity = $this->activity();
        $owner = User::factory()->create(['login' => 'owner']);
        $logger = User::factory()->create(['login' => 'logger-only']);
        $ownEditor = User::factory()->create(['login' => 'own-editor']);
        $editor = User::factory()->create(['login' => 'editor']);
        $colleague = User::factory()->create(['login' => 'colleague']);
        $this->grant($world->project, $owner, 'owner', ['log_time', 'edit_own_time_entries', 'view_time_entries']);
        $this->grant($world->project, $logger, 'logger', ['log_time', 'view_time_entries']);
        $this->grant($world->project, $ownEditor, 'own', ['edit_own_time_entries']);
        $this->grant($world->project, $editor, 'editor', ['edit_time_entries', 'view_time_entries']);
        $issue = $this->issue($world, $owner, 'Editable');
        $entries = app(TimeEntryService::class);
        $entry = $entries->create($owner, $world->project, [
            'activity_id' => $activity->id,
            'issue_id' => $issue->id,
            'hours' => 2,
            'spent_on' => '2026-10-01',
            'comments' => 'Keep author',
        ]);
        $createdOn = (string) $entry->getRawOriginal('created_on');

        $this->expectDenied(fn () => $entries->update($logger, $entry, ['hours' => 9]), 'edit_time_entries');
        $this->expectDenied(fn () => $entries->delete($logger, $entry), 'edit_time_entries');
        $this->expectDenied(fn () => $entries->update($ownEditor, $entry, ['hours' => 9]), 'edit_time_entries');

        app(ProjectService::class)->disableModule($world->project, 'time_tracking');
        $this->expectDenied(fn () => $entries->update($owner, $entry, ['hours' => 9]), 'edit_time_entries');
        app(ProjectService::class)->enableModule($world->project, 'time_tracking');

        $updated = $entries->update($owner, $entry, [
            'hours' => 4,
            'comments' => '   ',
            'spent_on' => '2021-01-01',
        ]);
        $this->assertEqualsWithDelta(4.0, (float) $updated->hours, 0.001);
        $this->assertNull($updated->comments);
        $this->assertSame('2021-01-01', substr((string) $updated->getRawOriginal('spent_on'), 0, 10));
        $this->assertSame(2020, (int) $updated->tyear);
        $this->assertSame(1, (int) $updated->tmonth);
        $this->assertSame(53, (int) $updated->tweek);
        $this->assertSame($owner->id, (int) $updated->author_id);
        $this->assertSame($createdOn, (string) $updated->getRawOriginal('created_on'));

        $edited = $entries->update($editor, $updated, ['comments' => 'Edited']);
        $this->assertSame('Edited', $edited->comments);
        $this->assertSame($owner->id, (int) $edited->user_id);
        $this->expectDenied(
            fn () => $entries->update($editor, $edited, ['user_id' => $colleague->id]),
            'log_time_for_other_users',
        );

        $this->grant($world->project, $editor, 'reassign', ['log_time_for_other_users']);
        $moved = $entries->update($editor, $edited, ['user_id' => $colleague->id, 'issue_id' => null]);
        $this->assertSame($colleague->id, (int) $moved->user_id);
        $this->assertSame($owner->id, (int) $moved->author_id);
        $this->assertNull($moved->issue_id);
        $this->expectDenied(fn () => $entries->delete($owner, $moved), 'edit_time_entries');

        CustomValue::query()->create([
            'custom_field_id' => 0,
            'customized_type' => 'TimeEntry',
            'customized_id' => $moved->id,
            'value' => 'bound',
        ]);
        CustomValue::query()->create([
            'custom_field_id' => 0,
            'customized_type' => 'TimeEntry',
            'customized_id' => $moved->id + 1000,
            'value' => 'keep',
        ]);
        $entries->delete($editor, $moved);
        $this->assertNull(TimeEntry::query()->find($moved->id));
        $this->assertSame(0, CustomValue::query()->where('customized_id', $moved->id)->count());
        $this->assertSame('keep', CustomValue::query()->where('customized_id', $moved->id + 1000)->value('value'));
    }

    private function world(string $identifier): DomainFixture
    {
        $world = DomainFixture::boot($identifier);
        app(ProjectService::class)->enableModule($world->project, 'issue_tracking');
        app(ProjectService::class)->enableModule($world->project, 'time_tracking');

        return $world;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(Project $project, User $user, string $name, array $permissions, string $timeVisibility = 'all'): void
    {
        $role = Role::query()->create([
            'name' => $name,
            'builtin' => 0,
            'assignable' => true,
            'permissions' => $permissions,
            'issues_visibility' => 'default',
            'time_entries_visibility' => $timeVisibility,
        ]);
        app(MembershipService::class)->assignRole($project, $user, $role);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activity(string $name = 'Development', array $overrides = []): Enumeration
    {
        $activity = Enumeration::query()->create([
            'name' => $name,
            'type' => 'TimeEntryActivity',
            'active' => true,
            'is_default' => false,
            'position' => 1,
            ...$overrides,
        ]);
        $this->assertInstanceOf(Enumeration::class, $activity);

        return $activity;
    }

    private function issue(DomainFixture $world, User $author, string $subject, ?Project $project = null): Issue
    {
        $issue = Issue::query()->create([
            'project_id' => ($project ?? $world->project)->id,
            'tracker_id' => $world->tracker->id,
            'status_id' => $world->newStatus->id,
            'priority_id' => $world->priority->id,
            'author_id' => $author->id,
            'subject' => $subject,
            'done_ratio' => 0,
            'is_private' => false,
            'lock_version' => 0,
        ]);
        $this->assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    private function expectDenied(callable $callback, string $permission): void
    {
        try {
            $callback();
            $this->fail('Expected permission '.$permission.' to deny the write.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame($permission, $denied->permission);
        }
    }

    private function expectInvalid(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail($message);
        } catch (PermissionDeniedException $denied) {
            $this->fail('Permission '.$denied->permission.' was denied; expected '.$message);
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
