<?php

namespace Tests\Feature;

use App\Domain\Attachments\AttachmentService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Models\CustomValue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class CustomFieldAttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_writes_digest_and_binds_when_the_custom_value_is_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 02:20:00'));
        $paths = [];
        try {
            $world = DomainFixture::boot('cf-upload');
            $world->join();
            $fields = app(CustomFieldService::class);
            $issues = app(IssueService::class);
            $attachments = app(AttachmentService::class);
            $field = $fields->save([
                'type' => 'IssueCustomField',
                'name' => 'Spec',
                'field_format' => 'attachment',
                'is_for_all' => true,
                'tracker_ids' => [$world->tracker->id],
                'format_store' => ['extensions_allowed' => 'pdf, png'],
            ]);

            $pdf = $attachments->store($world->user, 'folder/Spec.PDF', '%PDF', null, 'A spec');
            $paths[] = $attachments->absolutePath($pdf);
            $this->assertNull($pdf->container_type);
            $this->assertNull($pdf->container_id);
            $this->assertSame('Spec.PDF', $pdf->filename);
            $this->assertSame('A spec', $pdf->description);
            $this->assertSame('2026/10', $pdf->disk_directory);
            $this->assertSame('261005022000_Spec.PDF', $pdf->disk_filename);
            $this->assertSame(hash('sha256', '%PDF'), $pdf->digest);
            $this->assertSame(strlen('%PDF'), (int) $pdf->filesize);
            $this->assertSame('application/pdf', $pdf->content_type);
            $this->assertSame($world->user->id, (int) $pdf->author_id);
            $this->assertSame('%PDF', file_get_contents($paths[0]));

            $again = $attachments->store($world->user, 'Spec.PDF', 'NEXT');
            $paths[] = $attachments->absolutePath($again);
            $this->assertSame('261005022001_Spec.PDF', $again->disk_filename);
            $this->assertSame(hash('sha256', 'NEXT'), $again->digest);

            $hashed = $attachments->store($world->user, 'résumé.pdf', 'R');
            $paths[] = $attachments->absolutePath($hashed);
            $this->assertSame('résumé.pdf', $hashed->filename);
            $this->assertSame('261005022000_'.hash('sha256', 'résumé.pdf'), $hashed->disk_filename);

            $issue = $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'With file',
                'custom_fields' => [
                    ['id' => $field->id, 'value' => (string) $pdf->id],
                ],
            ]);
            $pdf->refresh();
            $this->assertSame('Issue', $pdf->container_type);
            $this->assertSame($issue->id, (int) $pdf->container_id);
            $this->assertSame(
                (string) $pdf->id,
                CustomValue::query()->where('custom_field_id', $field->id)->where('customized_id', $issue->id)->value('value'),
            );

            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $field->id, 'value' => (string) $again->id],
                ],
            ]);
            $again->refresh();
            $pdf->refresh();
            $this->assertSame($issue->id, (int) $again->container_id);
            $this->assertSame($issue->id, (int) $pdf->container_id);

            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $field->id, 'value' => ''],
                ],
            ]);
            $again->refresh();
            $this->assertSame($issue->id, (int) $again->container_id);
            $this->assertSame(
                0,
                CustomValue::query()->where('custom_field_id', $field->id)->where('customized_id', $issue->id)->count(),
            );
        } finally {
            Carbon::setTestNow();
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_upload_rejects_a_bad_extension_and_a_foreign_container(): void
    {
        $world = DomainFixture::boot('cf-upload-deny');
        $world->join();
        $fields = app(CustomFieldService::class);
        $issues = app(IssueService::class);
        $attachments = app(AttachmentService::class);
        $field = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Spec',
            'field_format' => 'attachment',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
            'format_store' => ['extensions_allowed' => ['pdf']],
        ]);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Host',
        ]);
        $other = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Other',
        ]);

        $paths = [];
        try {
            $text = $attachments->store($world->user, 'notes.txt', 'abcd');
            $paths[] = $attachments->absolutePath($text);
            try {
                $issues->update($world->user, $issue->fresh(), [
                    'custom_fields' => [
                        ['id' => $field->id, 'value' => (string) $text->id],
                    ],
                ]);
                $this->fail('Attachment extensions must be enforced.');
            } catch (CustomFieldValidationException $exception) {
                $this->assertStringContainsString('extension', $exception->getMessage());
            }
            $text->refresh();
            $this->assertNull($text->container_type);
            $this->assertNull($text->container_id);

            $owned = $attachments->store($world->user, 'owned.pdf', 'OWN', 'application/x-spec', null, $other);
            $paths[] = $attachments->absolutePath($owned);
            $this->assertSame('application/x-spec', $owned->content_type);
            $this->assertSame($other->id, (int) $owned->container_id);
            try {
                $issues->update($world->user, $issue->fresh(), [
                    'custom_fields' => [
                        ['id' => $field->id, 'value' => (string) $owned->id],
                    ],
                ]);
                $this->fail('An attachment bound to another record must be rejected.');
            } catch (CustomFieldValidationException $exception) {
                $this->assertStringContainsString('not attached', $exception->getMessage());
            }
            $owned->refresh();
            $this->assertSame($other->id, (int) $owned->container_id);

            $longType = $attachments->store($world->user, 'blank.pdf', 'x', str_repeat('a', 256));
            $paths[] = $attachments->absolutePath($longType);
            $this->assertNull($longType->content_type);

            try {
                $attachments->store($world->user, '../..', 'nope');
                $this->fail('A filename that is only a parent segment must be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('Filename', $exception->getMessage());
            }
            try {
                $attachments->store($world->user, 'ok.pdf', 'x', null, str_repeat('d', 256));
                $this->fail('A long description must be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('Description', $exception->getMessage());
            }
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
