<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\CustomFields\CustomFieldValue;
use App\Domain\CustomFields\CustomValueService;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Domain\Projects\ProjectService;
use App\Domain\Workflow\WorkflowService;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Workflow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class CustomFieldValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_required_list_multiple_and_defaults(): void
    {
        $world = DomainFixture::boot('cf-issue');
        $world->join();
        $fields = app(CustomFieldService::class);
        $issues = app(IssueService::class);

        $required = CustomField::factory()->create([
            'name' => 'Severity',
            'is_required' => true,
            'is_filter' => true,
            'searchable' => true,
        ]);
        $required->trackers()->sync([$world->tracker->id]);

        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Missing severity',
            ]);
            $this->fail('A required custom field must block create.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('Value is required.', $exception->getMessage());
            $this->assertSame(0, $world->project->issues()->count());
        }

        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'With severity',
            'custom_fields' => [
                ['id' => $required->id, 'value' => 'high'],
            ],
        ]);
        $this->assertSame('high', CustomValue::query()->where('custom_field_id', $required->id)->value('value'));
        $this->assertSame('Issue', CustomValue::query()->where('custom_field_id', $required->id)->value('customized_type'));

        $list = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Tags',
            'field_format' => 'list',
            'possible_values' => ['A', 'B'],
            'multiple' => true,
            'is_for_all' => true,
            'searchable' => true,
            'tracker_ids' => [$world->tracker->id],
            'format_store' => ['edit_tag_style' => 'check_box'],
        ]);
        $saved = $list->fresh();
        $this->assertNotNull($saved);
        $this->assertSame(['A', 'B'], $saved->possible_values);
        $this->assertSame(['edit_tag_style' => 'check_box'], $saved->format_store);

        $issues->update($world->user, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $list->id, 'value' => ['B', 'A']],
            ],
        ]);
        $rows = CustomValue::query()->where('custom_field_id', $list->id)->orderBy('id')->pluck('value')->all();
        $this->assertSame(['B', 'A'], $rows);

        $read = app(CustomValueService::class)->read($world->user, $issue->fresh());
        $byName = [];
        foreach ($read as $value) {
            $byName[$value->name] = $value->toArray();
        }
        $this->assertSame(['B', 'A'], $byName['Tags']['value']);
        $this->assertSame(['B', 'A'], $byName['Tags']['raw']);
        $this->assertSame('high', $byName['Severity']['value']);

        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $list->id, 'value' => ['C']],
                ],
            ]);
            $this->fail('List values must be one of possible_values.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('possible values', $exception->getMessage());
        }

        try {
            $fields->save([
                'type' => 'IssueCustomField',
                'name' => 'Bad multiple',
                'field_format' => 'int',
                'multiple' => true,
                'is_for_all' => true,
                'tracker_ids' => [$world->tracker->id],
            ]);
            $this->fail('Integer fields cannot be multiple.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('multiple', $exception->getMessage());
        }
    }

    public function test_tracker_and_project_scope_exclude_the_field(): void
    {
        $world = DomainFixture::boot('cf-scope');
        $world->join();
        $projects = app(ProjectService::class);
        $otherTracker = Tracker::query()->create([
            'name' => 'Task',
            'default_status_id' => $world->newStatus->id,
            'position' => 2,
        ]);
        $projects->attachTracker($world->project, $otherTracker);
        $other = $projects->create([
            'name' => 'Other',
            'identifier' => 'other-scope',
            'is_public' => true,
        ]);
        $projects->enableModule($other, 'issue_tracking');
        $projects->attachTracker($other, $world->tracker);

        $scoped = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Customer',
            'field_format' => 'string',
            'is_for_all' => false,
            'tracker_ids' => [$world->tracker->id],
            'project_ids' => [$other->id],
        ]);

        $issues = app(IssueService::class);
        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Wrong project',
                'custom_fields' => [
                    ['id' => $scoped->id, 'value' => 'Acme'],
                ],
            ]);
            $this->fail('A project-scoped field must not apply outside its projects.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('does not apply', $exception->getMessage());
        }

        $trackerOnly = CustomField::factory()->create([
            'name' => 'Bug only',
            'is_for_all' => true,
        ]);
        $trackerOnly->trackers()->sync([$world->tracker->id]);
        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $otherTracker->id,
                'subject' => 'Wrong tracker',
                'custom_fields' => [
                    ['id' => $trackerOnly->id, 'value' => 'x'],
                ],
            ]);
            $this->fail('A tracker-scoped field must not apply to other trackers.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('does not apply', $exception->getMessage());
        }

        app(MembershipService::class)->assignRole($other, $world->user, $world->role);
        $created = $issues->create($world->user, $other, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'In scope',
            'custom_fields' => [
                ['id' => $scoped->id, 'value' => 'Acme'],
            ],
        ]);
        $this->assertSame('Acme', CustomValue::query()->where('customized_id', $created->id)->value('value'));
    }

    public function test_hidden_field_follows_custom_field_roles_and_editable_flag(): void
    {
        $world = DomainFixture::boot('cf-roles');
        $world->join();
        $managerRole = Role::query()->create([
            'name' => 'Manager',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues', 'add_issues', 'edit_issues'],
            'issues_visibility' => 'all',
        ]);
        $manager = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $manager, $managerRole);

        $hidden = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Budget',
            'field_format' => 'int',
            'visible' => false,
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
            'role_ids' => [$managerRole->id],
        ]);
        $locked = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Code',
            'field_format' => 'string',
            'editable' => false,
            'default_value' => 'N/A',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);

        $issues = app(IssueService::class);
        $values = app(CustomValueService::class);

        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Sees hidden',
                'custom_fields' => [
                    ['id' => $hidden->id, 'value' => '10'],
                ],
            ]);
            $this->fail('A role outside custom_fields_roles cannot write a hidden field.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('not visible', $exception->getMessage());
        }

        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Hidden omitted',
        ]);
        $developerRead = $values->read($world->user, $issue);
        $names = array_map(static fn ($value): string => $value->name, $developerRead);
        $this->assertNotContains('Budget', $names);
        $this->assertContains('Code', $names);
        $this->assertSame('N/A', CustomValue::query()->where('custom_field_id', $locked->id)->value('value'));

        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $locked->id, 'value' => 'changed'],
                ],
            ]);
            $this->fail('editable = false blocks non-admins.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('not editable', $exception->getMessage());
        }

        $issues->update($manager, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $hidden->id, 'value' => 42],
            ],
        ]);
        $this->assertSame('42', CustomValue::query()->where('custom_field_id', $hidden->id)->value('value'));
        $managerRead = $values->read($manager, $issue->fresh());
        $seen = [];
        foreach ($managerRead as $value) {
            $seen[$value->name] = $value->value;
        }
        $this->assertSame(42, $seen['Budget']);
        $this->assertSame([], $this->names($values->read($world->user, $issue->fresh()), 'Budget'));

        $admin = User::factory()->create(['admin' => true]);
        $adminSeen = [];
        foreach ($values->read($admin, $issue->fresh()) as $value) {
            $adminSeen[$value->name] = $value->value;
        }
        $this->assertSame(42, $adminSeen['Budget']);
        $issues->update($admin, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $locked->id, 'value' => 'ADMIN'],
            ],
        ]);
        $this->assertSame('ADMIN', CustomValue::query()->where('custom_field_id', $locked->id)->value('value'));
    }

    public function test_workflow_rules_and_bool_date_values(): void
    {
        $world = DomainFixture::boot('cf-rules');
        $world->join();
        $bool = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Approved',
            'field_format' => 'bool',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);
        $date = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Reviewed',
            'field_format' => 'date',
            'default_value' => '1',
            'format_store' => ['default_value_mode' => 'date_offset'],
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);

        Carbon::setTestNow('2026-09-01 15:00:00');
        try {
            $issue = app(IssueService::class)->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Flags',
                'custom_fields' => [
                    ['id' => $bool->id, 'value' => false],
                ],
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame('0', CustomValue::query()->where('custom_field_id', $bool->id)->value('value'));
        $this->assertSame('2026-09-02', CustomValue::query()->where('custom_field_id', $date->id)->value('value'));

        Workflow::query()->create([
            'type' => WorkflowService::TYPE_FIELD_PERMISSION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $world->role->id,
            'old_status_id' => $world->newStatus->id,
            'new_status_id' => 0,
            'field_name' => (string) $bool->id,
            'rule' => WorkflowService::RULE_READONLY,
        ]);
        try {
            app(IssueService::class)->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $bool->id, 'value' => true],
                ],
            ]);
            $this->fail('A readonly workflow rule blocks the custom field.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
        $this->assertSame('0', CustomValue::query()->where('custom_field_id', $bool->id)->value('value'));

        try {
            app(IssueService::class)->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $date->id, 'value' => '31-09-2026'],
                ],
            ]);
            $this->fail('Dates must be YYYY-MM-DD.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('YYYY-MM-DD', $exception->getMessage());
        }
    }

    public function test_project_user_and_time_entry_values(): void
    {
        $world = DomainFixture::boot('cf-other');
        $world->join();
        $fields = app(CustomFieldService::class);
        $values = app(CustomValueService::class);

        $projectField = $fields->save([
            'type' => 'ProjectCustomField',
            'name' => 'Alias',
            'field_format' => 'string',
            'is_required' => true,
        ]);
        try {
            $values->sync($world->user, $world->project, [], false);
            $this->fail('Required project fields are enforced on sync.');
        } catch (CustomFieldValidationException) {
            $this->assertSame(0, CustomValue::query()->where('custom_field_id', $projectField->id)->count());
        }
        $values->sync($world->user, $world->project, [
            $projectField->id => 'demo-alias',
        ], false);
        $projectRead = $values->read($world->user, $world->project->fresh());
        $this->assertSame('demo-alias', $projectRead[0]->value);
        $this->assertSame('Project', $world->project->customValues()->value('customized_type'));

        $userField = $fields->save([
            'type' => 'UserCustomField',
            'name' => 'Badge',
            'field_format' => 'string',
        ]);
        $values->sync($world->user, $world->user, [
            $userField->id => 'gold',
        ], false);
        $this->assertSame('User', CustomValue::query()->where('custom_field_id', $userField->id)->value('customized_type'));

        $group = User::factory()->create(['type' => User::TYPE_GROUP, 'lastname' => 'Ops']);
        $groupField = $fields->save([
            'type' => 'GroupCustomField',
            'name' => 'Kind',
            'field_format' => 'string',
        ]);
        try {
            $values->sync($world->user, $group, [$userField->id => 'nope'], false);
            $this->fail('A user field does not apply to a group.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('does not apply', $exception->getMessage());
        }
        $values->sync($world->user, $group, [$groupField->id => 'team'], false);
        $this->assertSame('Group', $group->customValues()->value('customized_type'));

        $activity = Enumeration::query()->create([
            'name' => 'Development',
            'type' => 'TimeEntryActivity',
            'active' => true,
            'position' => 1,
        ]);
        $entry = TimeEntry::query()->create([
            'project_id' => $world->project->id,
            'issue_id' => null,
            'user_id' => $world->user->id,
            'author_id' => $world->user->id,
            'activity_id' => $activity->id,
            'hours' => 1.5,
            'spent_on' => '2026-09-01',
            'tyear' => 2026,
            'tmonth' => 9,
            'tweek' => 36,
        ]);
        $hours = $fields->save([
            'type' => 'TimeEntryCustomField',
            'name' => 'Overtime',
            'field_format' => 'float',
        ]);
        try {
            $values->sync($world->user, $entry, [$hours->id => 'nope'], false);
            $this->fail('Float fields reject non-numeric text.');
        } catch (CustomFieldValidationException) {
            $this->assertNull(CustomValue::query()->where('custom_field_id', $hours->id)->first());
        }
        $values->sync($world->user, $entry, [$hours->id => '1.25'], false);
        $this->assertSame('1.25', $entry->customValues()->value('value'));
        $this->assertSame(1.25, $values->read($world->user, $entry->fresh())[0]->value);
    }

    public function test_hidden_field_workflow_merge_uses_roles_without_access_as_readonly(): void
    {
        $world = DomainFixture::boot('cf-merge');
        $world->join();
        $reporter = Role::query()->create([
            'name' => 'Reporter',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues', 'add_issues', 'edit_issues'],
        ]);
        app(MembershipService::class)->assignRole($world->project, $world->user, $reporter);

        $field = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Score',
            'field_format' => 'int',
            'visible' => false,
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
            'role_ids' => [$world->role->id],
        ]);
        $issues = app(IssueService::class);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Merge',
            'custom_fields' => [
                ['id' => $field->id, 'value' => '1'],
            ],
        ]);
        $this->assertSame('1', CustomValue::query()->where('custom_field_id', $field->id)->value('value'));

        Workflow::query()->create([
            'type' => WorkflowService::TYPE_FIELD_PERMISSION,
            'tracker_id' => $world->tracker->id,
            'role_id' => $world->role->id,
            'old_status_id' => $world->newStatus->id,
            'new_status_id' => 0,
            'field_name' => (string) $field->id,
            'rule' => WorkflowService::RULE_READONLY,
        ]);
        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $field->id, 'value' => '2'],
                ],
            ]);
            $this->fail('Readonly on every role, including a synthetic readonly role, blocks the write.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
        $this->assertSame('1', CustomValue::query()->where('custom_field_id', $field->id)->value('value'));
    }

    public function test_link_enumeration_progressbar_and_attachment_round_trip(): void
    {
        $world = DomainFixture::boot('cf-deferred');
        $world->join();
        $fields = app(CustomFieldService::class);
        $issues = app(IssueService::class);
        $tracker = [$world->tracker->id];

        try {
            $fields->save([
                'type' => 'IssueCustomField',
                'name' => 'Searchable link',
                'field_format' => 'link',
                'searchable' => true,
                'is_for_all' => true,
                'tracker_ids' => $tracker,
            ]);
            $this->fail('Link fields cannot be searchable.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('searchable', $exception->getMessage());
        }

        try {
            $fields->save([
                'type' => 'IssueCustomField',
                'name' => 'Multi progress',
                'field_format' => 'progressbar',
                'multiple' => true,
                'is_for_all' => true,
                'tracker_ids' => $tracker,
            ]);
            $this->fail('Progress bar fields cannot be multiple.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('multiple', $exception->getMessage());
        }

        $link = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Homepage',
            'field_format' => 'link',
            'regexp' => '^https://',
            'min_length' => 12,
            'max_length' => 80,
            'is_for_all' => true,
            'is_filter' => true,
            'tracker_ids' => $tracker,
            'format_store' => ['url_pattern' => 'https://links.test/%value%'],
        ]);
        $savedLink = $link->fresh();
        $this->assertNotNull($savedLink);
        $this->assertSame(['url_pattern' => 'https://links.test/%value%'], $savedLink->format_store);

        $enumeration = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Kind',
            'field_format' => 'enumeration',
            'multiple' => true,
            'is_for_all' => true,
            'is_filter' => true,
            'tracker_ids' => $tracker,
        ]);
        $alpha = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $enumeration->id,
            'name' => 'Alpha',
            'active' => true,
            'position' => 1,
        ]);
        $beta = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $enumeration->id,
            'name' => 'Beta',
            'active' => true,
            'position' => 2,
        ]);
        $retired = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $enumeration->id,
            'name' => 'Retired',
            'active' => false,
            'position' => 3,
        ]);
        $fields->save([
            'id' => $enumeration->id,
            'default_value' => (string) $alpha->id,
        ]);

        $progress = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Done',
            'field_format' => 'progressbar',
            'is_for_all' => true,
            'is_filter' => true,
            'tracker_ids' => $tracker,
            'format_store' => ['ratio_interval' => 10],
        ]);
        $attachmentField = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Spec',
            'field_format' => 'attachment',
            'is_for_all' => true,
            'is_filter' => true,
            'tracker_ids' => $tracker,
            'format_store' => ['extensions_allowed' => ['pdf', 'png']],
        ]);

        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Deferred formats',
            'custom_fields' => [
                ['id' => $link->id, 'value' => 'https://example.test/a'],
                ['id' => $enumeration->id, 'value' => [(string) $beta->id, (string) $alpha->id]],
                ['id' => $progress->id, 'value' => '50'],
            ],
        ]);
        $file = Attachment::query()->create([
            'container_type' => 'Issue',
            'container_id' => $issue->id,
            'filename' => 'spec.pdf',
            'disk_filename' => 'spec.pdf',
            'filesize' => 20,
            'author_id' => $world->user->id,
        ]);
        $issues->update($world->user, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $attachmentField->id, 'value' => (string) $file->id],
            ],
        ]);

        $byName = [];
        foreach (app(CustomValueService::class)->read($world->user, $issue->fresh()) as $value) {
            $byName[$value->name] = $value->toArray();
        }
        $this->assertSame('https://example.test/a', $byName['Homepage']['value']);
        $this->assertSame(['https://example.test/a'], $byName['Homepage']['raw']);
        $this->assertSame('link', $byName['Homepage']['field_format']);
        $this->assertSame([$beta->id, $alpha->id], $byName['Kind']['value']);
        $this->assertSame([(string) $beta->id, (string) $alpha->id], $byName['Kind']['raw']);
        $this->assertSame(50, $byName['Done']['value']);
        $this->assertSame(['50'], $byName['Done']['raw']);
        $this->assertSame($file->id, $byName['Spec']['value']);
        $this->assertSame([(string) $file->id], $byName['Spec']['raw']);

        $withDefault = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Default kind',
        ]);
        $this->assertSame(
            (string) $alpha->id,
            CustomValue::query()->where('custom_field_id', $enumeration->id)->where('customized_id', $withDefault->id)->value('value'),
        );

        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $link->id, 'value' => 'http://example.test'],
                ],
            ]);
            $this->fail('Link values must match the pattern.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('pattern', $exception->getMessage());
        }

        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $enumeration->id, 'value' => [(string) $retired->id]],
                ],
            ]);
            $this->fail('Inactive enumerations must be rejected.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('not active', $exception->getMessage());
        }

        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $progress->id, 'value' => '15'],
                ],
            ]);
            $this->fail('Progress must follow the ratio interval.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('ratio interval', $exception->getMessage());
        }

        $text = Attachment::query()->create([
            'container_type' => 'Issue',
            'container_id' => $issue->id,
            'filename' => 'notes.txt',
            'disk_filename' => 'notes.txt',
            'filesize' => 4,
            'author_id' => $world->user->id,
        ]);
        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $attachmentField->id, 'value' => (string) $text->id],
                ],
            ]);
            $this->fail('Attachment extensions must be enforced.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('extension', $exception->getMessage());
        }

        $issues->update($world->user, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $attachmentField->id, 'value' => ''],
            ],
        ]);
        $this->assertSame(
            0,
            CustomValue::query()->where('custom_field_id', $attachmentField->id)->where('customized_id', $issue->id)->count(),
        );
        $this->assertSame('50', CustomValue::query()->where('custom_field_id', $progress->id)->where('customized_id', $issue->id)->value('value'));
    }

    public function test_legacy_possible_values_text_decodes_as_null(): void
    {
        $field = CustomField::factory()->create([
            'name' => 'Legacy',
            'possible_values' => ['A'],
        ]);
        DB::table('custom_fields')->where('id', $field->id)->update([
            'possible_values' => "---\n- A\n",
            'format_store' => "---\n:url_pattern: http://example.test/%value%\n",
        ]);
        $fresh = $field->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->possible_values);
        $this->assertNull($fresh->format_store);
    }

    /**
     * @param  list<CustomFieldValue>  $values
     * @return list<string>
     */
    private function names(array $values, string $only): array
    {
        $names = [];
        foreach ($values as $value) {
            if ($value->name === $only) {
                $names[] = $value->name;
            }
        }

        return $names;
    }
}
