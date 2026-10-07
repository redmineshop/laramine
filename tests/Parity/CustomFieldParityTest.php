<?php

namespace Tests\Parity;

use App\Domain\Attachments\AttachmentService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\CustomFields\CustomValueService;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Domain\CustomFields\Formats\LinkFormat;
use App\Domain\CustomFields\UserFieldOptions;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Issues\IssueService;
use App\Domain\Projects\ProjectService;
use App\Domain\Projects\VersionAvailability;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares custom-field values, outbound link URLs, version sharing, journal
 * diffs, attachment deletion, user-field option lists, and enumeration or
 * document hosts to the shared pin.
 *
 * The server does not request a link URL. History lines for `cf` details are
 * not part of this comparison. The documents table is not migrated.
 */
class CustomFieldParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_values_round_trip_through_their_formats(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $specs = $expected['values'];
        $this->assertIsArray($specs);
        $issue = $this->issue($this->intField($expected, 'issue_id'));
        $stored = $this->storedValues($issue->id);
        $this->assertCount(count($specs), $stored);

        $formats = app(FieldFormatRegistry::class);
        foreach ($specs as $spec) {
            $this->assertIsArray($spec);
            $fieldId = $this->intField($spec, 'custom_field_id');
            $raw = $spec['raw'] ?? null;
            $this->assertIsArray($raw);
            $this->assertSame($raw, $stored[$fieldId] ?? null);
            $field = CustomField::query()->find($fieldId);
            $this->assertInstanceOf(CustomField::class, $field);
            $this->assertSame($this->stringField($spec, 'field_format'), (string) $field->field_format);
            $format = $formats->get((string) $field->field_format);
            if (count($raw) === 1) {
                $again = $format->serialize($field, $format->cast($field, $this->stringListItem($raw, 0)));
            } else {
                $casts = [];
                foreach ($raw as $item) {
                    $this->assertIsString($item);
                    $casts[] = $format->cast($field, $item);
                }
                $again = $format->serialize($field, $casts);
            }
            $this->assertSame($raw, $again, (string) $field->field_format);
        }

        $ada = $this->actor('ada');
        $read = app(CustomValueService::class)->read($ada, $issue);
        $seen = [];
        foreach ($read as $value) {
            $seen[$value->id] = $value->raw;
        }
        foreach ($specs as $spec) {
            $this->assertIsArray($spec);
            $fieldId = $this->intField($spec, 'custom_field_id');
            $this->assertSame($spec['raw'], $seen[$fieldId] ?? null);
        }

        $hiddenId = $this->intField($expected, 'hidden_field_id');
        $beaSeen = [];
        foreach (app(CustomValueService::class)->read($this->actor($this->stringField($expected, 'hidden_login')), $issue) as $value) {
            $beaSeen[$value->id] = $value->raw;
        }
        $this->assertArrayNotHasKey($hiddenId, $beaSeen);
        $this->assertArrayHasKey($hiddenId, $seen);

        $rowsBefore = $this->valueRows($issue->id);
        $journalCount = Journal::query()->where('journalized_id', $issue->id)->count();
        $inputs = [];
        foreach ($read as $value) {
            $inputs[] = [
                'id' => $value->id,
                'value' => count($value->raw) > 1 ? $value->raw : ($value->raw[0] ?? null),
            ];
        }
        app(IssueService::class)->update($ada, $issue, ['custom_fields' => $inputs]);
        $this->assertSame($rowsBefore, $this->valueRows($issue->id));
        $this->assertSame($journalCount, Journal::query()->where('journalized_id', $issue->id)->count());
    }

    public function test_outbound_link_urls_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $outbound = $expected['outbound'];
        $rules = $expected['outbound_rules'];
        $rejected = $expected['rejected_patterns'];
        $this->assertIsArray($outbound);
        $this->assertIsArray($rules);
        $this->assertIsArray($rejected);
        $ada = $this->actor('ada');

        foreach ($outbound as $case) {
            $this->assertIsArray($case);
            $id = $this->intField($case, 'custom_value_id');
            $this->actingAs($ada)
                ->getJson('/custom-fields/links/'.$id)
                ->assertOk()
                ->assertJsonPath('url', $this->stringField($case, 'url'));
        }

        $format = app(LinkFormat::class);
        foreach ($rules as $rule) {
            $this->assertIsArray($rule);
            $this->assertSame(
                $this->stringField($rule, 'url'),
                $format->outboundUrl(new CustomField, $this->stringField($rule, 'value'), 1),
            );
        }
        foreach ($rejected as $pattern) {
            $this->assertIsString($pattern);
            $errors = $format->validateDefinition(new CustomField([
                'format_store' => ['url_pattern' => $pattern],
            ]));
            $this->assertNotSame([], $errors, $pattern);
        }
    }

    public function test_version_sharing_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $sharing = $expected['sharing'];
        $extra = $expected['sharing_other_root'];
        $reject = $expected['reject_version'];
        $locked = $expected['reject_locked'];
        $accept = $expected['accept_shared'];
        $this->assertIsArray($sharing);
        $this->assertIsArray($extra);
        $this->assertIsArray($reject);
        $this->assertIsArray($locked);
        $this->assertIsArray($accept);
        $availability = app(VersionAvailability::class);

        foreach ($sharing as $case) {
            $this->assertIsArray($case);
            $this->assertSame(
                $this->boolField($case, 'available'),
                $availability->available($this->version($this->intField($case, 'version_id')), $this->project($this->intField($case, 'project_id'))),
            );
        }

        $other = app(ProjectService::class)->create([
            'name' => $this->stringField($extra, 'name'),
            'identifier' => $this->stringField($extra, 'identifier'),
        ]);
        $cases = $extra['cases'] ?? null;
        $this->assertIsArray($cases);
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $this->assertSame(
                $this->boolField($case, 'available'),
                $availability->available($this->version($this->intField($case, 'version_id')), $other),
            );
        }

        $this->assertRejectedVersion($reject);
        $this->assertRejectedVersion($locked);

        $issue = $this->issue($this->intField($accept, 'issue_id'));
        $updated = app(IssueService::class)->update($this->actor($this->stringField($accept, 'login')), $issue, [
            'custom_fields' => [[
                'id' => $this->intField($accept, 'field_id'),
                'value' => (string) $this->intField($accept, 'version_id'),
            ]],
        ]);
        $this->assertSame(
            (string) $this->intField($accept, 'version_id'),
            CustomValue::query()->where('custom_field_id', $this->intField($accept, 'field_id'))->where('customized_id', $updated->id)->value('value'),
        );
        $journalSpec = $accept['journal'] ?? null;
        $this->assertIsArray($journalSpec);
        $this->assertSame($journalSpec, $this->detailRow($this->latestDetail($updated->id)));
    }

    public function test_journal_diffs_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $journal = $expected['journal'];
        $multiple = $expected['journal_multiple'];
        $clear = $expected['journal_clear'];
        $this->assertIsArray($journal);
        $this->assertIsArray($multiple);
        $this->assertIsArray($clear);
        $issues = app(IssueService::class);
        $actor = $this->actor($this->stringField($journal, 'login'));
        $issue = $this->issue($this->intField($journal, 'issue_id'));

        $issues->update($actor, $issue, [
            'subject' => $this->stringField($journal, 'subject'),
            'custom_fields' => [
                ['id' => 1, 'value' => 'next'],
                [
                    'id' => $this->intField($journal, 'unchanged_field_id'),
                    'value' => $this->stringField($journal, 'unchanged_value'),
                ],
            ],
        ]);
        $this->assertSame($journal['details'], $this->detailRows($this->intField($journal, 'issue_id')));

        $issues->update($actor, $this->issue($this->intField($journal, 'issue_id')), [
            'custom_fields' => [[
                'id' => $this->intField($multiple, 'field_id'),
                'value' => $multiple['value'],
            ]],
        ]);
        $multiDetail = $this->latestDetail($this->intField($journal, 'issue_id'));
        $this->assertSame($this->stringField($multiple, 'property'), (string) $multiDetail->property);
        $this->assertSame($this->stringField($multiple, 'prop_key'), (string) $multiDetail->prop_key);
        $this->assertSame($this->stringField($multiple, 'old_value'), $multiDetail->old_value);
        $this->assertSame($this->stringField($multiple, 'value_text'), $multiDetail->value);

        $issues->update($actor, $this->issue($this->intField($journal, 'issue_id')), [
            'custom_fields' => [[
                'id' => $this->intField($clear, 'field_id'),
                'value' => null,
            ]],
        ]);
        $cleared = $this->latestDetail($this->intField($journal, 'issue_id'));
        $this->assertSame($this->stringField($clear, 'property'), (string) $cleared->property);
        $this->assertSame($this->stringField($clear, 'prop_key'), (string) $cleared->prop_key);
        $this->assertSame($this->stringField($clear, 'old_value'), $cleared->old_value);
        $this->assertNull($cleared->value);
    }

    public function test_attachment_delete_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $spec = $expected['attachment_delete'];
        $this->assertIsArray($spec);
        $actor = $this->actor($this->stringField($spec, 'login'));
        $issue = $this->issue(1);
        $trackerId = (int) $issue->tracker_id;
        $field = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Pin file',
            'field_format' => 'attachment',
            'is_for_all' => true,
            'tracker_ids' => [$trackerId],
            'format_store' => ['extensions_allowed' => 'pdf'],
        ]);
        $files = app(AttachmentService::class);
        $attachment = $files->store($actor, 'pin.pdf', '%PDF');
        $path = $files->absolutePath($attachment);
        $loose = $files->store($actor, 'loose.pdf', 'LOOSE', null, null, $issue);
        $loosePath = $files->absolutePath($loose);
        try {
            app(IssueService::class)->update($actor, $issue, [
                'custom_fields' => [[
                    'id' => $field->id,
                    'value' => (string) $attachment->id,
                ]],
            ]);
            $noted = app(IssueService::class)->update($actor, $issue->fresh() ?? $issue, [
                'notes' => 'A note for a journal file',
            ]);
            $journal = Journal::query()->where('journalized_id', $noted->id)->where('notes', 'A note for a journal file')->first();
            $this->assertInstanceOf(Journal::class, $journal);
            $journalFile = $files->store($actor, 'note.pdf', 'NOTE', null, null, $journal);
            $journalPath = $files->absolutePath($journalFile);

            $this->actingAs($this->actor($this->stringField($spec, 'outsider_login')))
                ->deleteJson('/custom-fields/attachments/'.$attachment->id)
                ->assertForbidden();
            $this->actingAs($this->actor($this->stringField($spec, 'denied_login')))
                ->deleteJson('/custom-fields/attachments/'.$attachment->id)
                ->assertForbidden()
                ->assertJsonPath('message', 'Permission denied: edit_issues');
            $this->actingAs($actor)
                ->deleteJson('/custom-fields/attachments/'.$loose->id)
                ->assertNotFound();
            $this->actingAs($actor)
                ->deleteJson('/custom-fields/attachments/'.$journalFile->id)
                ->assertNotFound();
            $this->assertFileExists($loosePath);
            $this->assertFileExists($journalPath);

            $this->actingAs($actor)
                ->deleteJson('/custom-fields/attachments/'.$attachment->id)
                ->assertNoContent();

            $this->assertFileDoesNotExist($path);
            $this->assertNull(Attachment::query()->find($attachment->id));
            $this->assertNull(CustomValue::query()->where('custom_field_id', $field->id)->where('customized_id', $issue->id)->first());
            $detail = $this->latestDetail($issue->id);
            $this->assertSame($this->stringField($spec, 'property'), (string) $detail->property);
            $this->assertSame((string) $field->id, (string) $detail->prop_key);
            $this->assertSame((string) $attachment->id, $detail->old_value);
            $this->assertNull($detail->value);
            $this->assertSame(IssueJournalWriter::PROPERTY_CF, (string) $detail->property);
            $this->actingAs($actor)
                ->getJson('/custom-fields/attachments/'.$attachment->id)
                ->assertNotFound();

            if (is_file($journalPath)) {
                unlink($journalPath);
            }
            if (is_file($loosePath)) {
                unlink($loosePath);
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($loosePath)) {
                unlink($loosePath);
            }
        }
    }

    public function test_user_field_options_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->hosts();
        $options = app(UserFieldOptions::class);
        $edit = $expected['edit'] ?? null;
        $filter = $expected['filter'] ?? null;
        $this->assertIsArray($edit);
        $this->assertIsArray($filter);

        foreach ($edit as $case) {
            $this->assertIsArray($case);
            $this->assertEditCase($options, $case);
        }
        foreach ($filter as $case) {
            $this->assertIsArray($case);
            $this->assertFilterCase($options, $case);
        }

        $ignored = $expected['filter_ignores_role'] ?? null;
        $this->assertIsArray($ignored);
        $this->assertFilterCase($options, $ignored);

        $this->actingAs($this->actor('ada'))
            ->getJson('/custom-fields/'.$this->intField($expected, 'reject_format_field_id').'/users')
            ->assertNotFound();

        $intersection = $expected['intersection'] ?? null;
        $this->assertIsArray($intersection);
        $issues = [];
        foreach ($this->intList($intersection, 'issue_ids') as $issueId) {
            $issues[] = $this->issue($issueId);
        }
        $this->assertSame(
            $this->intList($intersection, 'ids'),
            $options->offeredForRecords(
                $this->actor($this->stringField($intersection, 'login')),
                $this->customField($this->intField($intersection, 'field_id')),
                $issues,
            ),
        );
    }

    public function test_user_field_write_visibility_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->hosts();
        $writes = $expected['writes'] ?? null;
        $this->assertIsArray($writes);
        $values = app(CustomValueService::class);

        foreach ($writes as $case) {
            $this->assertIsArray($case);
            $issue = $this->issue($this->intField($case, 'issue_id'));
            $fieldId = $this->intField($case, 'field_id');
            CustomValue::query()
                ->where('customized_type', 'Issue')
                ->where('customized_id', $issue->id)
                ->where('custom_field_id', $fieldId)
                ->delete();
            $userId = $this->intField($case, 'user_id');
            $error = $case['error'] ?? null;
            try {
                $values->sync($this->actor($this->stringField($case, 'login')), $issue, [
                    $fieldId => (string) $userId,
                ], false);
                $this->assertNull($error);
                $this->assertSame((string) $userId, $this->customValue($fieldId, $issue->id));
            } catch (CustomFieldValidationException $exception) {
                $this->assertIsString($error);
                $this->assertStringContainsString($error, $exception->getMessage());
            }
        }
    }

    public function test_stored_user_value_may_stay_when_it_is_no_longer_offered(): void
    {
        Redmine701Fixture::load();
        $expected = $this->hosts();
        $keep = $expected['keep_previous'] ?? null;
        $this->assertIsArray($keep);
        $issue = $this->issue($this->intField($keep, 'issue_id'));
        $fieldId = $this->intField($keep, 'field_id');
        $stored = (string) $this->intField($keep, 'stored_user_id');
        CustomValue::query()->create([
            'custom_field_id' => $fieldId,
            'customized_type' => 'Issue',
            'customized_id' => $issue->id,
            'value' => $stored,
        ]);

        app(CustomValueService::class)->sync($this->actor($this->stringField($keep, 'login')), $issue, [
            $fieldId => $stored,
        ], false);

        $this->assertSame($stored, $this->customValue($fieldId, $issue->id));
    }

    public function test_enumeration_and_document_hosts_match_the_pin(): void
    {
        $loaded = Redmine701Fixture::load();
        $expected = $this->hosts();
        $this->assertStoredHosts($expected);

        $override = $expected['project_activity'] ?? null;
        $this->assertIsArray($override);
        $activity = new Enumeration;
        $activity->forceFill([
            'id' => $loaded->nextId('enumerations'),
            'name' => $this->stringField($override, 'name'),
            'type' => 'TimeEntryActivity',
            'active' => true,
            'is_default' => false,
            'position' => 2,
            'project_id' => $this->intField($override, 'project_id'),
            'parent_id' => $this->intField($override, 'parent_id'),
        ])->save();

        $indexes = $expected['indexes'] ?? null;
        $this->assertIsArray($indexes);
        $admin = $this->actor('admin');
        foreach (['issue_priorities', 'time_entry_activities', 'document_categories'] as $slug) {
            $body = $indexes[$slug] ?? null;
            $this->assertIsArray($body);
            $this->actingAs($admin)->getJson('/enumerations/'.$slug)->assertOk()->assertExactJson($body);
        }
        Auth::logout();
        $this->getJson('/enumerations/issue_priorities')->assertForbidden();
        $this->actingAs($this->actor('bea'))->getJson('/enumerations/issue_priorities')->assertForbidden();

        $priority = $expected['priority_edit'] ?? null;
        $this->assertIsArray($priority);
        $priorityId = $this->intField($priority, 'enumeration_id');
        $priorityFields = $priority['fields'] ?? null;
        $this->assertIsArray($priorityFields);
        $this->actingAs($admin)
            ->getJson('/enumerations/'.$priorityId.'/custom-fields')
            ->assertOk()
            ->assertJsonPath('custom_fields', $priorityFields);
        $this->actingAs($admin)
            ->get('/enumerations/'.$priorityId.'/custom-fields')
            ->assertOk()
            ->assertSee('Priority note')
            ->assertSee('pinned');
        $this->actingAs($this->actor('bea'))
            ->getJson('/enumerations/'.$priorityId.'/custom-fields')
            ->assertForbidden();
        $this->actingAs($admin)
            ->putJson('/enumerations/'.$priorityId.'/custom-fields', [
                'custom_fields' => [['id' => 18, 'value' => '']],
            ])
            ->assertStatus(422)
            ->assertSee($this->stringField($priority, 'clear_error'), false);
        $this->assertSame('pinned', $this->customValue(18, $priorityId, 'IssuePriority'));

        $activityEdit = $expected['activity_edit'] ?? null;
        $this->assertIsArray($activityEdit);
        $activityId = $this->intField($activityEdit, 'enumeration_id');
        $activityRow = $this->enumeration($activityId);
        $adminFields = $activityEdit['admin'] ?? null;
        $this->assertIsArray($adminFields);
        $this->actingAs($admin)
            ->getJson('/enumerations/'.$activityId.'/custom-fields')
            ->assertOk()
            ->assertJsonPath('custom_fields', $adminFields);
        $hidden = app(CustomValueService::class)->read($this->actor($this->stringField($activityEdit, 'hidden_login')), $activityRow);
        $this->assertSame([], $hidden);
        $managerRead = app(CustomValueService::class)->read($this->actor($this->stringField($activityEdit, 'manager_login')), $activityRow);
        $this->assertCount(1, $managerRead);
        $this->assertSame(19, $managerRead[0]->id);
        $this->actingAs($this->actor($this->stringField($activityEdit, 'manager_login')))
            ->putJson('/enumerations/'.$activityId.'/custom-fields', [
                'custom_fields' => [['id' => 19, 'value' => 'nope']],
            ])
            ->assertForbidden();
        $this->assertSame('dev', $this->customValue(19, $activityId, 'TimeEntryActivity'));

        $grant = $expected['document_grant'] ?? null;
        $document = $expected['document'] ?? null;
        $this->assertIsArray($grant);
        $this->assertIsArray($document);
        $project = $this->project($this->intField($document, 'project_id'));
        $documentId = $this->intField($document, 'document_id');
        $this->actingAs($this->actor($this->stringField($document, 'reader')))
            ->getJson('/projects/'.$project->id.'/documents/'.$documentId.'/custom-fields')
            ->assertForbidden();

        $this->grantDocuments($grant);
        $fields = $document['fields'] ?? null;
        $this->assertIsArray($fields);
        $this->actingAs($admin)
            ->getJson('/projects/'.$project->id.'/documents/'.$documentId.'/custom-fields')
            ->assertOk()
            ->assertJsonPath('custom_fields', $fields);
        $reader = $this->actor($this->stringField($document, 'reader'));
        $this->actingAs($reader)
            ->get('/projects/'.$project->id.'/documents/'.$documentId.'/custom-fields')
            ->assertOk()
            ->assertSee('Doc label')
            ->assertSee($this->stringField($document, 'value'));
        $denied = $document['denied'] ?? null;
        $this->assertIsArray($denied);
        foreach ($denied as $login) {
            $this->assertIsString($login);
            $this->actingAs($this->actor($login))
                ->getJson('/projects/'.$project->id.'/documents/'.$documentId.'/custom-fields')
                ->assertForbidden();
        }

        $fieldId = $this->intField($document, 'field_id');
        $replacement = $this->stringField($document, 'replacement');
        $this->actingAs($reader)
            ->putJson('/projects/'.$project->id.'/documents/'.$documentId.'/custom-fields', [
                'custom_fields' => [['id' => $fieldId, 'value' => $replacement]],
            ])
            ->assertOk();
        $this->assertSame($replacement, $this->customValue($fieldId, $documentId, 'Document'));
        $this->actingAs($reader)
            ->putJson('/projects/'.$project->id.'/documents/'.$documentId.'/custom-fields', [
                'custom_fields' => [['id' => $fieldId, 'value' => '']],
            ])
            ->assertStatus(422);
        $this->assertSame($replacement, $this->customValue($fieldId, $documentId, 'Document'));

        $createId = $this->intField($document, 'create_id');
        $this->actingAs($reader)
            ->postJson('/projects/'.$project->id.'/documents/'.$createId.'/custom-fields', [
                'custom_fields' => [],
            ])
            ->assertOk();
        $this->assertSame($this->stringField($document, 'default'), $this->customValue($fieldId, $createId, 'Document'));

        $created = $expected['new_priority'] ?? null;
        $this->assertIsArray($created);
        $priorityRow = new Enumeration;
        $priorityRow->forceFill([
            'id' => $loaded->nextId('enumerations'),
            'name' => $this->stringField($created, 'name'),
            'type' => 'IssuePriority',
            'active' => true,
            'is_default' => false,
            'position' => 3,
        ])->save();
        $this->actingAs($admin)
            ->postJson('/enumerations/'.$priorityRow->id.'/custom-fields', [
                'custom_fields' => [],
            ])
            ->assertOk();
        $this->assertSame(
            $this->stringField($created, 'default'),
            $this->customValue(18, (int) $priorityRow->id, 'IssuePriority'),
        );
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Custom fields \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/CustomFieldParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/custom-fields/values.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/custom-fields/hosts.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function assertEditCase(UserFieldOptions $options, array $case): void
    {
        $viewer = $this->optionalActor($case);
        $projectId = $this->nullableInt($case, 'project_id');
        $record = $projectId === null ? null : $this->project($projectId);
        $field = $this->customField($this->intField($case, 'field_id'));
        $ids = $this->intList($case, 'ids');
        $this->assertSame($ids, $options->offered($viewer, $field, $record));
        $this->assertSame($this->boolField($case, 'me_label'), $options->editMeLabel($viewer, $field, $record));

        $this->act($viewer);
        $query = $projectId === null ? '' : '?project_id='.$projectId;
        $this->getJson('/custom-fields/'.$field->id.'/users'.$query)
            ->assertOk()
            ->assertExactJson([
                'ids' => $ids,
                'me_label' => $this->boolField($case, 'me_label'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function assertFilterCase(UserFieldOptions $options, array $case): void
    {
        $viewer = $this->optionalActor($case);
        $projectId = $this->nullableInt($case, 'project_id');
        $project = $projectId === null ? null : $this->project($projectId);
        $expected = [
            'ids' => $this->intList($case, 'ids'),
            'me' => $this->boolField($case, 'me'),
        ];
        $this->assertSame($expected, $options->filterList($viewer, $project));

        $this->act($viewer);
        $query = 'list=filter';
        if ($projectId !== null) {
            $query .= '&project_id='.$projectId;
        }
        $this->getJson('/custom-fields/'.$this->intField($case, 'field_id').'/users?'.$query)
            ->assertOk()
            ->assertExactJson($expected);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertStoredHosts(array $expected): void
    {
        $stored = $expected['stored'] ?? null;
        $this->assertIsArray($stored);
        foreach ($stored as $row) {
            $this->assertIsArray($row);
            $found = DB::table('custom_values')->where('id', $this->intField($row, 'id'))->first();
            $this->assertNotNull($found);
            $this->assertSame($this->intField($row, 'custom_field_id'), (int) $found->custom_field_id);
            $this->assertSame($this->stringField($row, 'customized_type'), $found->customized_type);
            $this->assertSame($this->intField($row, 'customized_id'), (int) $found->customized_id);
            $this->assertSame($this->stringField($row, 'value'), $found->value);
        }
    }

    /**
     * @param  array<string, mixed>  $grant
     */
    private function grantDocuments(array $grant): void
    {
        $role = Role::query()->find($this->intField($grant, 'role_id'));
        $this->assertInstanceOf(Role::class, $role);
        $names = $grant['permissions'] ?? null;
        $this->assertIsArray($names);
        $permissions = $role->permissions;
        foreach ($names as $name) {
            $this->assertIsString($name);
            $permissions[] = $name;
        }
        $role->permissions = array_values(array_unique($permissions));
        $role->save();
        app(ProjectService::class)->enableModule($this->project($this->intField($grant, 'project_id')), $this->stringField($grant, 'module'));
    }

    private function act(?User $viewer): void
    {
        if ($viewer instanceof User) {
            $this->actingAs($viewer);

            return;
        }

        Auth::logout();
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function optionalActor(array $case): ?User
    {
        if (! array_key_exists('login', $case) || $case['login'] === null) {
            return null;
        }

        return $this->actor($this->stringField($case, 'login'));
    }

    private function customField(int $id): CustomField
    {
        $field = CustomField::query()->find($id);
        $this->assertInstanceOf(CustomField::class, $field);

        return $field;
    }

    private function enumeration(int $id): Enumeration
    {
        $enumeration = Enumeration::query()->find($id);
        $this->assertInstanceOf(Enumeration::class, $enumeration);

        return $enumeration;
    }

    private function customValue(int $fieldId, int $customizedId, string $type = 'Issue'): string
    {
        $value = CustomValue::query()
            ->where('custom_field_id', $fieldId)
            ->where('customized_type', $type)
            ->where('customized_id', $customizedId)
            ->value('value');
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<int>
     */
    private function intList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value);
        $ids = [];
        foreach ($value as $id) {
            $this->assertIsInt($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function hosts(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/custom-fields/hosts.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }

    private function assertRejectedVersion(array $case): void
    {
        try {
            app(IssueService::class)->update(
                $this->actor($this->stringField($case, 'login')),
                $this->issue($this->intField($case, 'issue_id')),
                ['custom_fields' => [[
                    'id' => $this->intField($case, 'field_id'),
                    'value' => (string) $this->intField($case, 'version_id'),
                ]]],
            );
            $this->fail($this->stringField($case, 'message'));
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString($this->stringField($case, 'message'), $exception->getMessage());
        }
    }

    private function latestDetail(int $issueId): JournalDetail
    {
        $journal = $this->latestJournal($issueId);
        $this->assertInstanceOf(Journal::class, $journal);
        $detail = $journal->details()->orderByDesc('id')->first();
        $this->assertInstanceOf(JournalDetail::class, $detail);

        return $detail;
    }

    /**
     * @return list<array{property: string, prop_key: string, old_value: string|null, value: string|null}>
     */
    private function detailRows(int $issueId): array
    {
        $journal = $this->latestJournal($issueId);
        $this->assertInstanceOf(Journal::class, $journal);
        $rows = [];
        foreach ($journal->details()->orderBy('id')->get() as $detail) {
            $this->assertInstanceOf(JournalDetail::class, $detail);
            $rows[] = $this->detailRow($detail);
        }

        return $rows;
    }

    /**
     * @return array{property: string, prop_key: string, old_value: string|null, value: string|null}
     */
    private function detailRow(JournalDetail $detail): array
    {
        $old = $detail->old_value;
        $value = $detail->value;

        return [
            'property' => (string) $detail->property,
            'prop_key' => (string) $detail->prop_key,
            'old_value' => is_string($old) ? $old : null,
            'value' => is_string($value) ? $value : null,
        ];
    }

    private function latestJournal(int $issueId): ?Journal
    {
        $journal = Journal::query()
            ->where('journalized_type', IssueJournalWriter::JOURNALIZED_ISSUE)
            ->where('journalized_id', $issueId)
            ->orderByDesc('id')
            ->first();

        return $journal instanceof Journal ? $journal : null;
    }

    /**
     * @return array<int, list<string>>
     */
    private function storedValues(int $issueId): array
    {
        $grouped = [];
        $rows = DB::table('custom_values')
            ->where('customized_type', 'Issue')
            ->where('customized_id', $issueId)
            ->orderBy('id')
            ->get(['custom_field_id', 'value']);
        foreach ($rows as $row) {
            $value = $row->value;
            if (! is_string($value)) {
                continue;
            }
            $grouped[(int) $row->custom_field_id][] = $value;
        }

        return $grouped;
    }

    /**
     * @return list<array{id: int, custom_field_id: int, value: string}>
     */
    private function valueRows(int $issueId): array
    {
        $rows = [];
        $stored = DB::table('custom_values')
            ->where('customized_type', 'Issue')
            ->where('customized_id', $issueId)
            ->orderBy('id')
            ->get(['id', 'custom_field_id', 'value']);
        foreach ($stored as $row) {
            $value = $row->value;
            $this->assertIsString($value);
            $rows[] = [
                'id' => (int) $row->id,
                'custom_field_id' => (int) $row->custom_field_id,
                'value' => $value,
            ];
        }

        return $rows;
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

    private function version(int $id): Version
    {
        $version = Version::query()->find($id);
        $this->assertInstanceOf(Version::class, $version);

        return $version;
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
     * @param  list<mixed>  $row
     */
    private function stringListItem(array $row, int $index): string
    {
        $value = $row[$index] ?? null;
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
    private function boolField(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        $this->assertIsBool($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/custom-fields/values.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }
}
