<?php

namespace Tests\Parity;

use App\Domain\Acl\MembershipService;
use App\Domain\Attachments\AbsentThumbnailDecoder;
use App\Domain\Attachments\AttachmentService;
use App\Domain\Attachments\AttachmentThumbnailRenderer;
use App\Domain\Attachments\PngImage;
use App\Domain\Attachments\ThumbnailDecoder;
use App\Domain\Issues\IssueRelationService;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;
use ZipArchive;

/**
 * Compares attachment HTTP, thumbnail cache, and relation-delete journals to the shared pin.
 */
class AttachmentParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = storage_path('app/attachments/thumbnails');
        if (! is_dir($directory)) {
            return;
        }
        $matches = glob($directory.'/*');
        if ($matches === false) {
            return;
        }
        foreach ($matches as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_http_download_thumbnail_and_journals_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('attachments/http.json');
        $journals = $this->expectation('attachments/journals.json');
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $png = $this->png();

        $this->call('POST', '/attachments/upload?filename=guest.png', [], [], [], [], 'guest')
            ->assertStatus($this->intField($expected, 'guest_status'))
            ->assertJsonPath('message', $this->stringField($expected, 'guest_message'));

        $uploaded = $this->upload($ada, $this->stringField($expected, 'upload_filename'), $png, $this->stringField($expected, 'content_type'));
        $uploaded->assertOk();
        $token = $uploaded->json('token');
        $this->assertIsString($token);

        $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => '9.'.$this->digest('nope'),
            'issue_id' => $expected['issue_id'],
        ])->assertStatus($this->intField($expected, 'bad_token_status'))
            ->assertJsonPath('message', $this->stringField($expected, 'bad_token_message'));

        $claimed = $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => $token,
            'issue_id' => $expected['issue_id'],
        ])->assertOk();
        $attachmentId = $claimed->json('id');
        $this->assertIsInt($attachmentId);
        $attachment = Attachment::query()->findOrFail($attachmentId);

        $added = $journals['added'];
        $this->assertIsArray($added);
        $detail = JournalDetail::query()->where('property', 'attachment')->where('value', $added['value'])->first();
        $this->assertInstanceOf(JournalDetail::class, $detail);
        $this->assertNull($detail->old_value);
        $this->assertSame((string) $attachment->id, (string) $detail->prop_key);
        $journal = Journal::query()->findOrFail($detail->journal_id);
        $this->assertSame($this->intField($added, 'issue_id'), (int) $journal->journalized_id);
        $this->assertGreaterThan(8, (int) $journal->id);
        $this->assertSame($this->stringField($added, 'property'), (string) $detail->property);

        $download = $expected['download'];
        $this->assertIsArray($download);
        $response = $this->actingAs($ada)->get('/attachments/'.$attachment->id);
        $response->assertOk();
        $this->assertStringContainsString($this->stringField($download, 'content_type'), (string) $response->headers->get('content-type'));
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString($this->stringField($download, 'disposition'), $disposition);
        $this->assertStringContainsString($this->stringField($download, 'filename'), $disposition);
        $this->assertSame($this->intField($download, 'downloads'), (int) $attachment->refresh()->downloads);

        $this->actingAs($this->user($this->stringField($expected, 'outsider_login')))
            ->getJson('/attachments/'.$attachment->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'outsider_permission'));

        $this->assertFile($ada, $expected['pdf']);
        $this->assertFile($ada, $expected['binary']);

        $privateIssue = $expected['private_issue'];
        $this->assertIsArray($privateIssue);
        $secret = app(AttachmentService::class)->store(
            $this->user($this->stringField($privateIssue, 'allowed_login')),
            $this->stringField($privateIssue, 'filename'),
            $this->stringField($privateIssue, 'body'),
            $this->stringField($privateIssue, 'content_type'),
            null,
            Issue::query()->findOrFail($this->intField($privateIssue, 'issue_id')),
        );
        $this->actingAs($this->user($this->stringField($privateIssue, 'denied_login')))
            ->getJson('/attachments/'.$secret->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($privateIssue, 'denied_permission'));
        $this->actingAs($this->user($this->stringField($privateIssue, 'allowed_login')))
            ->get('/attachments/'.$secret->id)
            ->assertOk();

        $publicJournal = $expected['public_journal'];
        $this->assertIsArray($publicJournal);
        $publicToken = $this->token($ada, 'public.txt', 'public', 'text/plain');
        $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => $publicToken,
            'journal_id' => $publicJournal['journal_id'],
        ])->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($publicJournal, 'permission'));

        $ownPrivate = $expected['own_private_journal'];
        $this->assertIsArray($ownPrivate);
        $ownToken = $this->token($ada, 'own.txt', 'own', 'text/plain');
        $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => $ownToken,
            'journal_id' => $ownPrivate['journal_id'],
        ])->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($ownPrivate, 'permission'));

        $privateJournal = $expected['private_journal'];
        $this->assertIsArray($privateJournal);
        $privateToken = $this->token($admin, 'hidden.png', $png, 'image/png');
        $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => $privateToken,
            'journal_id' => $privateJournal['journal_id'],
        ])->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($privateJournal, 'claim_permission'));
        $hidden = $this->actingAs($admin)->postJson('/attachments/claim', [
            'token' => $privateToken,
            'journal_id' => $privateJournal['journal_id'],
        ])->assertOk();
        $hiddenId = $hidden->json('id');
        $this->assertIsInt($hiddenId);
        $this->actingAs($ada)->getJson('/attachments/'.$hiddenId)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($privateJournal, 'download_permission'));
        $this->actingAs($this->user($this->stringField($privateJournal, 'allowed_login')))
            ->get('/attachments/'.$hiddenId)
            ->assertOk();

        $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => $token,
            'issue_id' => $expected['issue_id'],
        ])->assertStatus(422)->assertJsonPath('message', $this->stringField($expected, 'second_claim_message'));

        $thumb = $expected['thumbnail'];
        $this->assertIsArray($thumb);
        $this->setting('thumbnails_enabled', $this->stringField($thumb, 'enabled'));
        $edge = $this->intField($thumb, 'size');
        $thumbResponse = $this->actingAs($ada)->get('/attachments/'.$attachment->id.'/thumbnail?size='.$edge);
        $thumbResponse->assertOk();
        $this->assertStringContainsString($this->stringField($thumb, 'content_type'), (string) $thumbResponse->headers->get('content-type'));
        $fitted = PngImage::fit($png, $edge);
        $this->assertSame($fitted, $thumbResponse->streamedContent());
        $this->assertSame([$this->intField($thumb, 'width'), $this->intField($thumb, 'height')], PngImage::size($fitted));

        $cache = app(AttachmentThumbnailRenderer::class)->cachePath($attachment, $edge);
        $this->assertTrue(is_file($cache));
        file_put_contents($cache, 'POISON');
        $this->assertSame('POISON', $this->actingAs($ada)->get('/attachments/'.$attachment->id.'/thumbnail?size='.$edge)->streamedContent());

        $this->setting('thumbnails_enabled', $this->stringField($thumb, 'disabled_value'));
        $this->actingAs($ada)->getJson('/attachments/'.$attachment->id.'/thumbnail?size='.$edge)
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($thumb, 'disabled_message'));

        $this->setting('thumbnails_enabled', $this->stringField($thumb, 'enabled'));
        $jpegToken = $this->token($ada, $this->stringField($thumb, 'unreadable_filename'), 'not-a-png', 'image/jpeg');
        $jpeg = $this->actingAs($ada)->postJson('/attachments/claim', [
            'token' => $jpegToken,
            'issue_id' => $expected['issue_id'],
        ])->assertOk();
        $jpegId = $jpeg->json('id');
        $this->assertIsInt($jpegId);
        $this->actingAs($ada)->getJson('/attachments/'.$jpegId.'/thumbnail?size='.$edge)
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($thumb, 'unreadable_message'));

        $projectFile = $expected['project_file'];
        $this->assertIsArray($projectFile);
        $project = Project::query()->findOrFail(1);
        $stored = app(AttachmentService::class)->store(
            $admin,
            $this->stringField($projectFile, 'filename'),
            $this->stringField($projectFile, 'body'),
            $this->stringField($projectFile, 'content_type'),
            null,
            $project,
        );
        $this->actingAs($ada)->getJson('/attachments/'.$stored->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($projectFile, 'denied_permission'));
        $this->actingAs($admin)->get('/attachments/'.$stored->id)->assertOk();
        $this->assertSame($this->intField($projectFile, 'downloads_after'), (int) $stored->refresh()->downloads);

        $versionFile = $expected['version_file'];
        $this->assertIsArray($versionFile);
        $version = Version::query()->findOrFail($this->intField($versionFile, 'version_id'));
        $versionStored = app(AttachmentService::class)->store(
            $admin,
            $this->stringField($versionFile, 'filename'),
            $this->stringField($versionFile, 'body'),
            $this->stringField($versionFile, 'content_type'),
            null,
            $version,
        );
        $this->actingAs($admin)->get('/attachments/'.$versionStored->id)->assertOk();
        $this->assertSame($this->intField($versionFile, 'downloads_after'), (int) $versionStored->refresh()->downloads);

        $limit = $expected['max_size'];
        $this->assertIsArray($limit);
        $this->setting('attachment_max_size', $this->stringField($limit, 'kilobytes'));
        $this->upload($ada, 'big.txt', str_repeat('a', $this->intField($limit, 'too_large_bytes')), 'text/plain')
            ->assertStatus(422)
            ->assertJsonPath('message', $this->stringField($limit, 'too_large_message'));
        $this->upload($ada, 'small.txt', str_repeat('b', $this->intField($limit, 'accepted_bytes')), 'text/plain')->assertOk();
        Setting::query()->where('name', 'attachment_max_size')->delete();

        $extensions = $expected['extensions'];
        $this->assertIsArray($extensions);
        $this->setting('attachment_extensions_allowed', $this->stringField($extensions, 'allowed'));
        $this->upload($ada, $this->stringField($extensions, 'rejected_filename'), 'pdf', 'application/pdf')
            ->assertStatus(422)
            ->assertJsonPath('message', $this->stringField($extensions, 'rejected_message'));
        Setting::query()->where('name', 'attachment_extensions_allowed')->delete();
        $this->setting('attachment_extensions_denied', $this->stringField($extensions, 'denied'));
        $this->upload($ada, $this->stringField($extensions, 'denied_filename'), 'exe', 'application/octet-stream')
            ->assertStatus(422)
            ->assertJsonPath('message', $this->stringField($extensions, 'rejected_message'));
        $this->setting('attachment_extensions_allowed', $this->stringField($extensions, 'allow_and_deny'));
        $this->upload($ada, $this->stringField($extensions, 'allow_and_deny_filename'), 'exe', 'application/octet-stream')
            ->assertStatus(422)
            ->assertJsonPath('message', $this->stringField($extensions, 'rejected_message'));
        Setting::query()->whereIn('name', ['attachment_extensions_allowed', 'attachment_extensions_denied'])->delete();

        $this->upload($ada, 'empty.txt', '', 'text/plain')
            ->assertStatus(422)
            ->assertJsonPath('message', $this->stringField($expected, 'empty_message'));

        $this->actingAs($ada)->deleteJson('/attachments/'.$attachment->id)->assertNoContent();
        $removed = $journals['removed'];
        $this->assertIsArray($removed);
        $gone = JournalDetail::query()->where('property', 'attachment')->where('old_value', $removed['old_value'])->first();
        $this->assertInstanceOf(JournalDetail::class, $gone);
        $this->assertNull($gone->value);
        $this->assertSame($this->stringField($removed, 'property'), (string) $gone->property);
        $removedJournal = Journal::query()->findOrFail($gone->journal_id);
        $this->assertSame($this->intField($removed, 'issue_id'), (int) $removedJournal->journalized_id);
        $this->assertNull(Attachment::query()->find($attachment->id));
    }

    public function test_relation_delete_journals_both_issues(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('attachments/journals.json');
        $relations = app(IssueRelationService::class);
        $deny = $expected['deny'];
        $this->assertIsArray($deny);
        $relation = IssueRelation::query()->findOrFail($this->intField($deny, 'relation_id'));
        try {
            $relations->remove($this->user($this->stringField($deny, 'login')), $relation);
            $this->fail($this->stringField($deny, 'permission'));
        } catch (PermissionDeniedException $denied) {
            $this->assertSame($this->stringField($deny, 'permission'), $denied->permission);
        }

        foreach ($this->listField($expected, 'deletes') as $step) {
            $row = IssueRelation::query()->findOrFail($this->intField($step, 'relation_id'));
            $relations->remove($this->user($this->stringField($step, 'login')), $row);
            $this->assertNull(IssueRelation::query()->find($row->id));
            foreach ($this->listField($step, 'details') as $detail) {
                $journal = Journal::query()
                    ->where('journalized_type', 'Issue')
                    ->where('journalized_id', $this->intField($detail, 'issue_id'))
                    ->orderByDesc('id')
                    ->first();
                $this->assertInstanceOf(Journal::class, $journal);
                $this->assertSame($this->intField($step, 'user_id'), (int) $journal->user_id);
                $stored = JournalDetail::query()->where('journal_id', $journal->id)->first();
                $this->assertInstanceOf(JournalDetail::class, $stored);
                $this->assertSame($this->stringField($detail, 'property'), (string) $stored->property);
                $this->assertSame($this->stringField($detail, 'prop_key'), (string) $stored->prop_key);
                $this->assertSame($detail['old_value'], $stored->old_value);
                $this->assertSame($detail['value'], $stored->value);
            }
        }
    }

    public function test_image_thumbnails_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('attachments/thumbnails.json');
        $ada = $this->user('ada');
        $decoder = app(ThumbnailDecoder::class);
        $this->setting('thumbnails_enabled', $this->stringField($expected, 'enabled'));
        $edge = $this->intField($expected, 'edge');
        $issueId = $this->intField($expected, 'issue_id');
        $poisoned = false;

        foreach ($this->stringList($expected, 'required') as $kind) {
            $this->assertTrue($decoder->supports($kind), $kind);
        }

        foreach ($this->listField($expected, 'formats') as $format) {
            $kind = $this->stringField($format, 'kind');
            $filename = $this->stringField($format, 'filename');
            if (! $decoder->supports($kind)) {
                $id = $this->claimFile($ada, $filename, 'not-an-image', 'application/octet-stream', $issueId);
                $this->actingAs($ada)->getJson('/attachments/'.$id.'/thumbnail?size='.$edge)
                    ->assertNotFound()
                    ->assertJsonPath('message', $this->stringField($expected, 'unreadable_message'));

                continue;
            }
            $bytes = $this->raster($kind, $this->intField($expected, 'width'), $this->intField($expected, 'height'));
            $id = $this->claimFile($ada, $filename, $bytes, 'application/octet-stream', $issueId);
            $response = $this->actingAs($ada)->get('/attachments/'.$id.'/thumbnail?size='.$edge);
            $response->assertOk();
            $this->assertStringContainsString($this->stringField($expected, 'content_type'), (string) $response->headers->get('content-type'));
            $fitted = $response->streamedContent();
            $this->assertSame(
                [$this->intField($expected, 'thumb_width'), $this->intField($expected, 'thumb_height')],
                PngImage::size($fitted),
                $kind,
            );
            if (! $poisoned) {
                $attachment = Attachment::query()->findOrFail($id);
                $cache = app(AttachmentThumbnailRenderer::class)->cachePath($attachment, $edge);
                $this->assertTrue(is_file($cache));
                file_put_contents($cache, $this->stringField($expected, 'cache_poison'));
                $this->assertSame(
                    $this->stringField($expected, 'cache_poison'),
                    $this->actingAs($ada)->get('/attachments/'.$id.'/thumbnail?size='.$edge)->streamedContent(),
                );
                $poisoned = true;
            }
        }

        $pdf = $expected['pdf'];
        $this->assertIsArray($pdf);
        $pdfId = $this->claimFile(
            $ada,
            $this->stringField($pdf, 'filename'),
            $this->stringField($pdf, 'body'),
            'application/pdf',
            $issueId,
        );
        $this->actingAs($ada)->getJson('/attachments/'.$pdfId.'/thumbnail?size='.$edge)
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($expected, 'not_image_message'));

        $this->app->instance(ThumbnailDecoder::class, new AbsentThumbnailDecoder);
        foreach ($this->app->make('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $jpeg = $this->raster('jpeg', $this->intField($expected, 'width'), $this->intField($expected, 'height'));
        $jpegId = $this->claimFile($ada, 'again.jpg', $jpeg, 'image/jpeg', $issueId);
        $this->actingAs($ada)->getJson('/attachments/'.$jpegId.'/thumbnail?size='.$edge)
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($expected, 'unreadable_message'));
        $pngId = $this->claimFile($ada, 'still.png', $this->png(), 'image/png', $issueId);
        $this->actingAs($ada)->get('/attachments/'.$pngId.'/thumbnail?size='.$edge)->assertOk();
    }

    public function test_bulk_download_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('attachments/bulk.json');
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $issueId = $this->intField($expected, 'issue_id');
        $files = app(AttachmentService::class);
        $issue = Issue::query()->findOrFail($issueId);

        $this->assertSame(
            $this->intField($expected, 'default_kilobytes'),
            intdiv(app(SettingValue::class)->bulkDownloadMaxBytes(), 1024),
        );

        $this->actingAs($ada)->getJson('/attachments/issues/'.$issueId.'/download')
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($expected, 'empty_message'));
        $this->actingAs($ada)->getJson('/attachments/issues/'.$this->intField($expected, 'missing_id').'/download')
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($expected, 'missing_message'));
        $this->app['auth']->logout();
        $this->getJson('/attachments/issues/'.$issueId.'/download')
            ->assertStatus($this->intField($expected, 'guest_status'))
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'guest_permission'));
        foreach ($this->stringList($expected, 'absent_object_types') as $objectType) {
            $this->get('/attachments/'.$objectType.'/1/download')->assertNotFound();
        }

        $one = $expected['one'];
        $this->assertIsArray($one);
        $files->store($ada, $this->stringField($one, 'filename'), $this->stringField($one, 'body'), 'text/plain', null, $issue);
        $single = $this->actingAs($ada)->get('/attachments/issues/'.$issueId.'/download');
        $single->assertOk();
        $this->assertStringContainsString($this->stringField($expected, 'zip_type'), (string) $single->headers->get('content-type'));
        $this->assertStringContainsString('issue-'.$issueId.'-attachments.zip', (string) $single->headers->get('content-disposition'));
        $this->assertSame(
            [$this->stringField($one, 'filename') => $this->stringField($one, 'body')],
            $this->zipEntries($single->getContent()),
        );

        foreach ($this->listField($expected, 'duplicates') as $file) {
            $files->store($ada, $this->stringField($file, 'filename'), $this->stringField($file, 'body'), 'text/plain', null, $issue);
        }
        $skipped = $files->store($ada, 'missing.bin', 'SKIP', 'application/octet-stream', null, $issue);
        unlink($files->absolutePath($skipped));
        $zip = $this->actingAs($ada)->get('/attachments/issues/'.$issueId.'/download');
        $zip->assertOk();
        $entries = $expected['entries'];
        $this->assertIsArray($entries);
        $this->assertSame($entries, $this->zipEntries($zip->getContent()));
        $this->assertSame(
            $this->intField($expected, 'downloads'),
            (int) Attachment::query()->where('container_type', 'Issue')->where('container_id', $issueId)->max('downloads'),
        );

        $this->actingAs($this->user($this->stringField($expected, 'outsider_login')))
            ->getJson('/attachments/issues/'.$issueId.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'outsider_permission'));

        $journalFile = $expected['journal_file'];
        $this->assertIsArray($journalFile);
        $hidden = $files->store($ada, $this->stringField($journalFile, 'filename'), $this->stringField($journalFile, 'body'), 'text/plain', null, $issue);
        $journalId = $this->intField($expected, 'private_journal_id');
        JournalDetail::query()->create([
            'journal_id' => $journalId,
            'property' => 'attachment',
            'prop_key' => (string) $hidden->id,
            'old_value' => null,
            'value' => $hidden->filename,
        ]);
        JournalDetail::query()->create([
            'journal_id' => $journalId,
            'property' => 'attachment',
            'prop_key' => '0',
            'old_value' => $this->stringField($expected, 'removed_filename'),
            'value' => null,
        ]);
        $journalZip = $this->actingAs($this->user($this->stringField($expected, 'private_login')))
            ->get('/attachments/journals/'.$journalId.'/download');
        $journalZip->assertOk();
        $this->assertStringContainsString('journal-'.$journalId.'-attachments.zip', (string) $journalZip->headers->get('content-disposition'));
        $this->assertSame(
            [$this->stringField($journalFile, 'filename') => $this->stringField($journalFile, 'body')],
            $this->zipEntries($journalZip->getContent()),
        );

        $project = Project::query()->findOrFail($this->intField($expected, 'project_id'));
        $projectFile = $expected['project_file'];
        $this->assertIsArray($projectFile);
        $stored = $files->store($admin, $this->stringField($projectFile, 'filename'), $this->stringField($projectFile, 'body'), 'text/plain', null, $project);
        $this->actingAs($this->user($this->stringField($expected, 'project_denied_login')))
            ->getJson('/attachments/projects/'.$project->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'project_denied_permission'));
        $projectZip = $this->actingAs($this->user($this->stringField($expected, 'project_allowed_login')))
            ->get('/attachments/projects/'.$project->id.'/download');
        $projectZip->assertOk();
        $this->assertStringContainsString('project-'.$project->id.'-attachments.zip', (string) $projectZip->headers->get('content-disposition'));
        $this->assertSame(
            [$this->stringField($projectFile, 'filename') => $this->stringField($projectFile, 'body')],
            $this->zipEntries($projectZip->getContent()),
        );
        $this->assertSame($this->intField($expected, 'downloads'), (int) $stored->refresh()->downloads);

        $member = User::factory()->create(['login' => $this->stringField($expected, 'member_login')]);
        $role = Role::query()->create([
            'name' => 'parity-files',
            'builtin' => 0,
            'position' => 15,
            'assignable' => true,
            'permissions' => ['view_files'],
            'issues_visibility' => 'default',
            'time_entries_visibility' => 'all',
        ]);
        app(MembershipService::class)->assignRole($project, $member, $role);
        $this->actingAs($member)->getJson('/attachments/projects/'.$project->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'project_denied_permission'));
        app(ProjectService::class)->enableModule($project, $this->stringField($expected, 'module'));
        $this->actingAs($member)->get('/attachments/projects/'.$project->id.'/download')->assertOk();

        $version = Version::query()->findOrFail($this->intField($expected, 'version_id'));
        $versionFile = $expected['version_file'];
        $this->assertIsArray($versionFile);
        $files->store($admin, $this->stringField($versionFile, 'filename'), $this->stringField($versionFile, 'body'), 'text/plain', null, $version);
        $this->actingAs($member)->getJson('/attachments/versions/'.$version->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'version_denied_permission'));
        $role->permissions = ['view_files', 'view_issues'];
        $role->save();
        $versionZip = $this->actingAs($member)->get('/attachments/versions/'.$version->id.'/download');
        $versionZip->assertOk();
        $this->assertStringContainsString('version-'.$version->id.'-attachments.zip', (string) $versionZip->headers->get('content-disposition'));
        $this->assertSame(
            [$this->stringField($versionFile, 'filename') => $this->stringField($versionFile, 'body')],
            $this->zipEntries($versionZip->getContent()),
        );

        $newsFile = $expected['news_file'];
        $this->assertIsArray($newsFile);
        $news = News::query()->create([
            'author_id' => $admin->id,
            'comments_count' => 0,
            'created_on' => now(),
            'description' => null,
            'project_id' => $project->id,
            'summary' => '',
            'title' => 'Pin release',
        ]);
        $this->actingAs($ada)->getJson('/attachments/news/'.$this->intField($expected, 'missing_id').'/download')
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($expected, 'missing_message'));
        $this->actingAs($ada)->getJson('/attachments/news/'.$news->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'news_permission'));
        $this->actingAs($admin)->getJson('/attachments/news/'.$news->id.'/download')
            ->assertNotFound()
            ->assertJsonPath('message', $this->stringField($expected, 'empty_message'));
        $storedNews = $this->bindContainer(
            $files,
            $admin,
            $this->stringField($newsFile, 'filename'),
            $this->stringField($newsFile, 'body'),
            'News',
            (int) $news->id,
        );
        $newsZip = $this->actingAs($admin)->get('/attachments/news/'.$news->id.'/download');
        $newsZip->assertOk();
        $this->assertStringContainsString('news-'.$news->id.'-attachments.zip', (string) $newsZip->headers->get('content-disposition'));
        $this->assertSame(
            [$this->stringField($newsFile, 'filename') => $this->stringField($newsFile, 'body')],
            $this->zipEntries($newsZip->getContent()),
        );
        $this->grantOnRole($this->intField($expected, 'news_role_id'), $this->stringField($expected, 'news_permission'));
        $this->actingAs($ada)->getJson('/attachments/news/'.$news->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'news_permission'));
        app(ProjectService::class)->enableModule($project, $this->stringField($expected, 'news_module'));
        $this->actingAs($ada)->get('/attachments/news/'.$news->id.'/download')->assertOk();
        $this->assertSame($this->intField($expected, 'downloads'), (int) $storedNews->refresh()->downloads);

        $documentFile = $expected['document_file'];
        $this->assertIsArray($documentFile);
        $document = Document::query()->create([
            'category_id' => $this->intField($expected, 'document_category_id'),
            'created_on' => now(),
            'description' => null,
            'project_id' => $project->id,
            'title' => 'Pin guide',
        ]);
        $this->actingAs($ada)->getJson('/attachments/documents/'.$document->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'document_permission'));
        $storedDocument = $this->bindContainer(
            $files,
            $admin,
            $this->stringField($documentFile, 'filename'),
            $this->stringField($documentFile, 'body'),
            'Document',
            (int) $document->id,
        );
        $documentZip = $this->actingAs($admin)->get('/attachments/documents/'.$document->id.'/download');
        $documentZip->assertOk();
        $this->assertStringContainsString('document-'.$document->id.'-attachments.zip', (string) $documentZip->headers->get('content-disposition'));
        $this->assertSame(
            [$this->stringField($documentFile, 'filename') => $this->stringField($documentFile, 'body')],
            $this->zipEntries($documentZip->getContent()),
        );
        $this->grantOnRole($this->intField($expected, 'news_role_id'), $this->stringField($expected, 'document_permission'));
        $this->actingAs($ada)->getJson('/attachments/documents/'.$document->id.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission denied: '.$this->stringField($expected, 'document_permission'));
        app(ProjectService::class)->enableModule($project, $this->stringField($expected, 'document_module'));
        $this->actingAs($ada)->get('/attachments/documents/'.$document->id.'/download')->assertOk();
        $this->assertSame($this->intField($expected, 'downloads'), (int) $storedDocument->refresh()->downloads);

        $limit = $expected['limit'];
        $this->assertIsArray($limit);
        $this->setting('bulk_download_max_size', $this->stringField($limit, 'kilobytes'));
        $payload = str_repeat('x', $this->intField($limit, 'body_bytes'));
        $files->store($ada, 'wide-a.bin', $payload, 'application/octet-stream', null, $issue);
        $files->store($ada, 'wide-b.bin', $payload, 'application/octet-stream', null, $issue);
        $this->actingAs($ada)->getJson('/attachments/issues/'.$issueId.'/download')
            ->assertStatus($this->intField($limit, 'status'))
            ->assertJsonPath('message', $this->stringField($limit, 'message'));
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Time entries and attachments \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/AttachmentParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/attachments/http.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/attachments/journals.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/attachments/thumbnails.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/attachments/bulk.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
    }

    private function bindContainer(
        AttachmentService $files,
        User $author,
        string $filename,
        string $body,
        string $type,
        int $id,
    ): Attachment {
        $stored = $files->store($author, $filename, $body, 'text/plain', null, null);
        $stored->container_type = $type;
        $stored->container_id = $id;
        $stored->save();

        return $stored->refresh();
    }

    private function grantOnRole(int $roleId, string $permission): void
    {
        $role = Role::query()->find($roleId);
        $this->assertInstanceOf(Role::class, $role);
        $names = $role->permissions;
        $this->assertIsArray($names);
        $names[] = $permission;
        $role->permissions = array_values(array_unique($names));
        $role->save();
    }

    private function claimFile(User $actor, string $filename, string $body, string $contentType, int $issueId): int
    {
        $token = $this->token($actor, $filename, $body, $contentType);
        $claimed = $this->actingAs($actor)->postJson('/attachments/claim', [
            'token' => $token,
            'issue_id' => $issueId,
        ])->assertOk();
        $id = $claimed->json('id');
        $this->assertIsInt($id);

        return $id;
    }

    private function raster(string $kind, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 255, 0, 0);
        $this->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        ob_start();
        $written = match ($kind) {
            'gif' => imagegif($image),
            'jpeg' => imagejpeg($image),
            'bmp' => imagebmp($image),
            'webp' => imagewebp($image),
            'avif' => function_exists('imageavif') ? imageavif($image) : false,
            default => false,
        };
        $bytes = ob_get_clean();
        imagedestroy($image);
        $this->assertTrue($written);
        $this->assertIsString($bytes);

        return $bytes;
    }

    /**
     * @return array<string, string>
     */
    private function zipEntries(string|false $contents): array
    {
        $this->assertIsString($contents);
        $path = tempnam(sys_get_temp_dir(), 'assert-zip');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path) === true);
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $this->assertIsString($name);
                $bytes = $zip->getFromIndex($index);
                $this->assertIsString($bytes);
                $entries[$name] = $bytes;
            }

            return $entries;
        } finally {
            $zip->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function png(): string
    {
        $rgb = '';
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rgb .= $x < 2 ? "\xff\x00\x00" : "\x00\x00\xff";
            }
        }

        return PngImage::encode(4, 2, $rgb);
    }

    private function upload(User $actor, string $filename, string $body, ?string $contentType): TestResponse
    {
        $query = 'filename='.rawurlencode($filename);
        if ($contentType !== null && $contentType !== '') {
            $query .= '&content_type='.rawurlencode($contentType);
        }

        return $this->actingAs($actor)->call(
            'POST',
            '/attachments/upload?'.$query,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'],
            $body,
        );
    }

    private function token(User $actor, string $filename, string $body, string $contentType): string
    {
        $response = $this->upload($actor, $filename, $body, $contentType);
        $response->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function assertFile(User $actor, mixed $spec): void
    {
        $this->assertIsArray($spec);
        $token = $this->token(
            $actor,
            $this->stringField($spec, 'filename'),
            $this->stringField($spec, 'body'),
            $this->stringField($spec, 'content_type'),
        );
        $claimed = $this->actingAs($actor)->postJson('/attachments/claim', [
            'token' => $token,
            'issue_id' => 1,
        ])->assertOk();
        $id = $claimed->json('id');
        $this->assertIsInt($id);
        $response = $this->actingAs($actor)->get('/attachments/'.$id);
        $response->assertOk();
        $this->assertStringContainsString($this->stringField($spec, 'content_type'), (string) $response->headers->get('content-type'));
        $this->assertStringContainsString($this->stringField($spec, 'disposition'), (string) $response->headers->get('content-disposition'));
        $this->assertSame($this->stringField($spec, 'body'), $response->streamedContent());
    }

    private function digest(string $body): string
    {
        return hash('sha256', $body);
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user, $login);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(string $name): array
    {
        $path = base_path('tests/Parity/fixtures/redmine-7.0.1/expectations/'.$name);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

    /**
     * @param  array<mixed>  $row
     * @return list<array<mixed>>
     */
    private function listField(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value, $key);
        $this->assertTrue(array_is_list($value), $key);

        return $value;
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
}
