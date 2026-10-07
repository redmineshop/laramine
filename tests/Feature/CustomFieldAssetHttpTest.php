<?php

namespace Tests\Feature;

use App\Domain\Attachments\AttachmentService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\CustomFields\CustomValueService;
use App\Domain\Issues\IssueService;
use App\Domain\Projects\ProjectService;
use App\Models\Attachment;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * HTTP download of an attachment custom value and HTTP resolution of a link.
 *
 * These tests are Laramine behavior on MySQL. They do not compare rows with
 * a Redmine 7.0.1 database. Parity stays NOT VERIFIED.
 */
class CustomFieldAssetHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_downloads_an_attachment_custom_value(): void
    {
        $world = DomainFixture::boot('cf-http-file');
        $world->join();
        $paths = [];
        try {
            [$issue, $attachment] = $this->issueFile($world, 'Spec.PDF', '%PDF');
            $paths[] = app(AttachmentService::class)->absolutePath($attachment);

            $response = $this->actingAs($world->user)->get('/custom-fields/attachments/'.$attachment->id);

            $response->assertOk();
            $response->assertDownload('Spec.PDF');
            $response->assertHeader('content-type', 'application/pdf');
            $this->assertSame('%PDF', $response->streamedContent());
            $attachment->refresh();
            $this->assertSame(1, (int) $attachment->downloads);
            $this->assertSame($issue->id, (int) $attachment->container_id);

            $this->actingAs($world->user)->get('/custom-fields/attachments/'.$attachment->id)->assertOk();
            $attachment->refresh();
            $this->assertSame(2, (int) $attachment->downloads);
        } finally {
            $this->unlinkFiles($paths);
        }
    }

    public function test_outsider_and_guest_cannot_download_an_issue_attachment(): void
    {
        $world = DomainFixture::boot('cf-http-deny');
        $world->join();
        $paths = [];
        try {
            [, $attachment] = $this->issueFile($world, 'notes.pdf', 'BODY');
            $paths[] = app(AttachmentService::class)->absolutePath($attachment);
            $outsider = User::factory()->create();

            $this->actingAs($outsider)
                ->getJson('/custom-fields/attachments/'.$attachment->id)
                ->assertForbidden()
                ->assertJsonPath('message', 'Permission denied: view_issues');

            $this->getJson('/custom-fields/attachments/'.$attachment->id)
                ->assertForbidden()
                ->assertJsonPath('message', 'Permission denied: view_issues');

            $attachment->refresh();
            $this->assertSame(0, (int) $attachment->downloads);
        } finally {
            $this->unlinkFiles($paths);
        }
    }

    public function test_hidden_attachment_field_follows_custom_field_roles(): void
    {
        $world = DomainFixture::boot('cf-http-hidden');
        $world->join();
        $paths = [];
        try {
            $admin = User::factory()->create(['admin' => true]);
            $field = app(CustomFieldService::class)->save([
                'type' => 'IssueCustomField',
                'name' => 'Secret',
                'field_format' => 'attachment',
                'visible' => false,
                'is_for_all' => true,
                'tracker_ids' => [$world->tracker->id],
                'role_ids' => [],
                'format_store' => ['extensions_allowed' => 'pdf'],
            ]);
            $attachments = app(AttachmentService::class);
            $file = $attachments->store($admin, 'secret.pdf', 'SECRET');
            $paths[] = $attachments->absolutePath($file);
            $issue = app(IssueService::class)->create($admin, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Hidden file',
                'custom_fields' => [
                    ['id' => $field->id, 'value' => (string) $file->id],
                ],
            ]);
            $this->assertSame($issue->id, (int) $file->fresh()?->container_id);

            $this->actingAs($world->user)
                ->getJson('/custom-fields/attachments/'.$file->id)
                ->assertForbidden()
                ->assertJsonPath('message', 'Permission denied: custom_field');

            $this->actingAs($admin)
                ->get('/custom-fields/attachments/'.$file->id)
                ->assertOk()
                ->assertDownload('secret.pdf');

            $file->refresh();
            $this->assertSame(1, (int) $file->downloads);
        } finally {
            $this->unlinkFiles($paths);
        }
    }

    public function test_cleared_or_unbound_attachment_is_not_served(): void
    {
        $world = DomainFixture::boot('cf-http-gone');
        $world->join();
        $paths = [];
        try {
            $issues = app(IssueService::class);
            $attachments = app(AttachmentService::class);
            [$issue, $attachment] = $this->issueFile($world, 'gone.pdf', 'GONE');
            $paths[] = $attachments->absolutePath($attachment);
            $fieldId = $this->fieldId($issue->id);
            $loose = $attachments->store($world->user, 'loose.pdf', 'LOOSE');
            $paths[] = $attachments->absolutePath($loose);

            $this->actingAs($world->user)
                ->getJson('/custom-fields/attachments/'.$loose->id)
                ->assertNotFound()
                ->assertJsonPath('message', 'Attachment is not a custom field value.');

            $issues->update($world->user, $issue->fresh() ?? $issue, [
                'custom_fields' => [
                    ['id' => $fieldId, 'value' => ''],
                ],
            ]);

            $this->actingAs($world->user)
                ->getJson('/custom-fields/attachments/'.$attachment->id)
                ->assertNotFound()
                ->assertJsonPath('message', 'Attachment is not a custom field value.');

            $attachment->refresh();
            $this->assertSame(0, (int) $attachment->downloads);
            $this->assertSame($issue->id, (int) $attachment->container_id);

            $restored = $attachments->store($world->user, 'missing.pdf', 'X');
            $restoredPath = $attachments->absolutePath($restored);
            $paths[] = $restoredPath;
            $issues->update($world->user, $issue->fresh() ?? $issue, [
                'custom_fields' => [
                    ['id' => $fieldId, 'value' => (string) $restored->id],
                ],
            ]);
            unlink($restoredPath);
            $this->actingAs($world->user)
                ->getJson('/custom-fields/attachments/'.$restored->id)
                ->assertNotFound()
                ->assertJsonPath('message', 'Attachment file is not stored.');
        } finally {
            $this->unlinkFiles($paths);
        }
    }

    public function test_member_resolves_a_link_and_an_outsider_is_denied(): void
    {
        $world = DomainFixture::boot('cf-http-link');
        $world->join();
        $field = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Ticket',
            'field_format' => 'link',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
            'format_store' => [
                'url_pattern' => 'https://tracker.test/%project_identifier%/issues/%id%?q=%value%',
            ],
        ]);
        $issue = app(IssueService::class)->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Linked',
            'custom_fields' => [
                ['id' => $field->id, 'value' => 'a b'],
            ],
        ]);
        $row = $this->valueRow($field->id, $issue->id);
        $outsider = User::factory()->create();

        $this->actingAs($world->user)
            ->getJson('/custom-fields/links/'.$row->id)
            ->assertOk()
            ->assertJsonPath('value', 'a b')
            ->assertJsonPath('url', 'https://tracker.test/cf-http-link/issues/'.$issue->id.'?q=a%20b');

        $this->actingAs($outsider)
            ->getJson('/custom-fields/links/'.$row->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: view_issues');

        $plain = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Plain',
            'field_format' => 'link',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);
        app(IssueService::class)->update($world->user, $issue->fresh() ?? $issue, [
            'custom_fields' => [
                ['id' => $plain->id, 'value' => 'https://example.test/a'],
            ],
        ]);
        $plainRow = $this->valueRow($plain->id, $issue->id);
        $this->actingAs($world->user)
            ->getJson('/custom-fields/links/'.$plainRow->id)
            ->assertOk()
            ->assertJsonPath('url', 'https://example.test/a');

        $text = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Note',
            'field_format' => 'string',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);
        app(IssueService::class)->update($world->user, $issue->fresh() ?? $issue, [
            'custom_fields' => [
                ['id' => $text->id, 'value' => 'hello'],
            ],
        ]);
        $textRow = $this->valueRow($text->id, $issue->id);
        $this->actingAs($world->user)
            ->getJson('/custom-fields/links/'.$textRow->id)
            ->assertNotFound()
            ->assertJsonPath('message', 'Custom value is not a link.');
    }

    public function test_public_project_link_is_readable_by_a_guest_and_a_private_project_is_not(): void
    {
        $world = DomainFixture::boot('cf-http-project');
        $world->join();
        $field = app(CustomFieldService::class)->save([
            'type' => 'ProjectCustomField',
            'name' => 'Homepage',
            'field_format' => 'link',
            'format_store' => ['url_pattern' => 'https://projects.test/%project_identifier%/%value%'],
        ]);
        app(CustomValueService::class)->sync($world->user, $world->project, [
            $field->id => 'home',
        ], false);
        $row = $this->valueRow($field->id, $world->project->id);

        $this->getJson('/custom-fields/links/'.$row->id)
            ->assertOk()
            ->assertJsonPath('url', 'https://projects.test/cf-http-project/home');

        $private = app(ProjectService::class)->create([
            'name' => 'Secret',
            'identifier' => 'cf-http-secret',
            'is_public' => false,
        ]);
        app(CustomValueService::class)->sync($world->user, $private, [
            $field->id => 'hidden',
        ], false);
        $hidden = $this->valueRow($field->id, $private->id);

        $this->getJson('/custom-fields/links/'.$hidden->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: view_project');

        $this->actingAs($world->user)
            ->getJson('/custom-fields/links/'.$hidden->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: view_project');
    }

    public function test_user_link_is_visible_to_that_user_and_an_admin_only(): void
    {
        $world = DomainFixture::boot('cf-http-user');
        $owner = $world->user;
        $other = User::factory()->create();
        $admin = User::factory()->create(['admin' => true]);
        $field = app(CustomFieldService::class)->save([
            'type' => 'UserCustomField',
            'name' => 'Site',
            'field_format' => 'link',
            'format_store' => ['url_pattern' => 'https://people.test/%value%'],
        ]);
        app(CustomValueService::class)->sync($owner, $owner, [
            $field->id => 'ada',
        ], false);
        $row = $this->valueRow($field->id, $owner->id);

        $this->actingAs($owner)
            ->getJson('/custom-fields/links/'.$row->id)
            ->assertOk()
            ->assertJsonPath('url', 'https://people.test/ada');

        $this->actingAs($other)
            ->getJson('/custom-fields/links/'.$row->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: custom_field');

        $this->actingAs($admin)
            ->getJson('/custom-fields/links/'.$row->id)
            ->assertOk()
            ->assertJsonPath('value', 'ada');

        $group = User::factory()->create(['type' => User::TYPE_GROUP, 'lastname' => 'Ops']);
        $groupField = app(CustomFieldService::class)->save([
            'type' => 'GroupCustomField',
            'name' => 'Board',
            'field_format' => 'link',
        ]);
        app(CustomValueService::class)->sync($admin, $group, [
            $groupField->id => 'https://groups.test/ops',
        ], false);
        $groupRow = $this->valueRow($groupField->id, $group->id);

        $this->actingAs($owner)
            ->getJson('/custom-fields/links/'.$groupRow->id)
            ->assertForbidden();
        $this->actingAs($admin)
            ->getJson('/custom-fields/links/'.$groupRow->id)
            ->assertOk()
            ->assertJsonPath('url', 'https://groups.test/ops');
    }

    /**
     * @return array{0: Issue, 1: Attachment}
     */
    private function issueFile(DomainFixture $world, string $filename, string $contents): array
    {
        $field = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Spec',
            'field_format' => 'attachment',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
            'format_store' => ['extensions_allowed' => 'pdf'],
        ]);
        $attachment = app(AttachmentService::class)->store($world->user, $filename, $contents);
        $issue = app(IssueService::class)->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'With file',
            'custom_fields' => [
                ['id' => $field->id, 'value' => (string) $attachment->id],
            ],
        ]);

        return [$issue, $attachment->fresh() ?? $attachment];
    }

    private function fieldId(int $issueId): int
    {
        $id = CustomValue::query()->where('customized_type', 'Issue')->where('customized_id', $issueId)->value('custom_field_id');
        $this->assertIsNumeric($id);

        return (int) $id;
    }

    private function valueRow(int $fieldId, int $recordId): CustomValue
    {
        $row = CustomValue::query()
            ->where('custom_field_id', $fieldId)
            ->where('customized_id', $recordId)
            ->first();
        $this->assertInstanceOf(CustomValue::class, $row);

        return $row;
    }

    /**
     * @param  list<string>  $paths
     */
    private function unlinkFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
