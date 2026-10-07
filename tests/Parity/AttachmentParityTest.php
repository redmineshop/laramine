<?php

namespace Tests\Parity;

use App\Domain\Attachments\AttachmentService;
use App\Domain\Attachments\AttachmentThumbnailRenderer;
use App\Domain\Attachments\PngImage;
use App\Domain\Issues\IssueRelationService;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares attachment HTTP, thumbnail cache, and relation-delete journals to the shared pin.
 */
class AttachmentParityTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Time entries and attachments \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/AttachmentParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/attachments/http.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/attachments/journals.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
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
