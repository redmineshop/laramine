<?php

namespace Tests\Parity;

use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\Boards\BoardService;
use App\Domain\Boards\MessageService;
use App\Domain\DomainException;
use App\Models\Attachment;
use App\Models\EnabledModule;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;
use ZipArchive;

/**
 * Compares boards, topics, replies, and the message zip to the pin.
 */
class BoardsParityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_topics_replies_and_the_zip_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $this->enable('boards');
        $this->grant(1, [
            'view_messages',
            'add_messages',
            'edit_own_messages',
            'delete_own_messages',
            'view_message_watchers',
            'add_message_watchers',
            'delete_message_watchers',
        ]);
        $this->grant(2, [
            'view_messages',
            'add_messages',
            'edit_messages',
            'edit_own_messages',
            'delete_messages',
            'delete_own_messages',
            'manage_boards',
            'view_message_watchers',
            'add_message_watchers',
            'delete_message_watchers',
        ]);
        $this->grant(7, ['view_messages']);

        $boards = app(BoardService::class);
        $messages = app(MessageService::class);
        $project = $this->project();
        $ada = $this->user('ada');
        $bea = $this->user('bea');
        $this->rejected(fn () => $boards->create($bea, $project, '  ', null, null, null), $this->stringField($expected, 'empty_name'));

        $general = $boards->create($bea, $project, $this->stringField($expected, 'board'), 'Talk', null, null);
        $help = $boards->create($bea, $project, $this->stringField($expected, 'child'), null, (int) $general->id, null);
        $this->assertSame(1, (int) $general->position);
        $this->assertSame(2, (int) $help->position);
        $this->assertSame((int) $general->id, (int) $help->parent_id);
        $this->rejected(fn () => $boards->update($bea, $general, 'General', 'Talk', (int) $help->id, null), $this->stringField($expected, 'cycle'));

        Carbon::setTestNow($this->stringField($expected, 'topic_at'));
        $this->rejected(fn () => $messages->postTopic($ada, $general, 'Sticky', 'no', 1, false, false), $this->stringField($expected, 'sticky_denied'));
        $topic = $messages->postTopic($ada, $general, $this->stringField($expected, 'topic'), 'hello', 0, false, false);
        Carbon::setTestNow($this->stringField($expected, 'reply_at'));
        $reply = $messages->reply($ada, $topic, null, 'answer', false);
        $this->assertSame($this->stringField($expected, 'reply_subject'), $reply->subject);
        $general->refresh();
        $topic->refresh();
        $this->assertSame(1, (int) $general->topics_count);
        $this->assertSame(2, (int) $general->messages_count);
        $this->assertSame((int) $reply->id, (int) $general->last_message_id);
        $this->assertSame(1, (int) $topic->replies_count);
        $this->assertSame((int) $reply->id, (int) $topic->last_reply_id);
        $this->assertSame($this->stringField($expected, 'reply_at'), $topic->updated_on?->format('Y-m-d H:i:s'));

        $messages->delete($ada, $reply);
        $topic->refresh();
        $general->refresh();
        $this->assertSame(0, (int) $topic->replies_count);
        $this->assertNull($topic->last_reply_id);
        $this->assertSame($this->stringField($expected, 'topic_at'), $topic->updated_on?->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $general->messages_count);

        $this->rejected(fn () => $messages->update($ada, $topic, 'Hello', 'hello', $this->intField($expected, 'sticky'), true), $this->stringField($expected, 'sticky_denied'));
        $messages->update($ada, $topic, 'Hello there', 'hello', null, null);
        $topic->refresh();
        $this->assertSame('Hello there', $topic->subject);
        $messages->update($bea, $topic, 'Hello', 'hello', $this->intField($expected, 'sticky'), true);
        $topic->refresh();
        $this->assertSame($this->intField($expected, 'sticky'), (int) $topic->sticky);
        $this->assertTrue((bool) $topic->locked);
        $this->rejected(fn () => $messages->reply($ada, $topic, null, 'later', false), $this->stringField($expected, 'locked_reply'));
        $staff = $messages->reply($bea, $topic, null, 'staff', false);
        $this->assertSame($this->stringField($expected, 'reply_subject'), $staff->subject);

        $journals = Journal::query()->count();
        $container = app(AttachmentContainerService::class);
        $first = $container->upload($ada, 'note.txt', 'alpha', 'text/plain');
        $second = $container->upload($ada, 'note.txt', 'beta', 'text/plain');
        $kept = $messages->attach($ada, $topic, $first['token'], 'note.txt', null);
        $messages->attach($ada, $topic, $second['token'], 'note.txt', null);
        $this->assertSame($journals, Journal::query()->count());
        $zip = $this->actingAs($ada)->get('/attachments/messages/'.$topic->id.'/download');
        $zip->assertOk();
        $this->assertStringContainsString('message-'.$topic->id.'-attachments.zip', (string) $zip->headers->get('content-disposition'));
        $this->assertSame($expected['zip_entries'], $this->zipEntries($zip->getContent()));
        $download = $this->actingAs($this->user('finn'))->get('/attachments/'.$kept->id);
        $download->assertOk();
        $this->assertSame('alpha', $download->streamedContent());
        $kept->refresh();
        $this->assertSame($this->intField($expected, 'downloads'), (int) $kept->downloads);
        $this->rejected(fn () => $messages->deleteAttachment($ada, $kept), $this->stringField($expected, 'attachment_denied'));
        $this->actingAs($bea)->delete('/attachments/'.$kept->id)->assertNoContent();
        $this->assertNull(Attachment::query()->find($kept->id));

        $messages->watch($ada, $staff);
        $this->assertSame([(int) $ada->id], $messages->watcherIds($ada, $topic));
        $messages->addWatcher($bea, $staff, $this->user('finn'));
        $this->assertSame([(int) $ada->id, (int) $this->user('finn')->id], $messages->watcherIds($bea, $topic));

        $messages->delete($bea, $staff);
        $messages->delete($bea, $topic);
        $general->refresh();
        $this->assertSame(0, (int) $general->topics_count);
        $this->assertSame(0, (int) $general->messages_count);
        $this->assertNull($general->last_message_id);

        $listed = $boards->index($ada, $project);
        $this->assertSame(['General', 'Help'], array_column($listed, 'name'));

        $project->status = Project::STATUS_CLOSED;
        $project->save();
        $this->assertCount(2, $boards->index($ada, $project->fresh() ?? $project));
        $this->rejected(fn () => $messages->postTopic($ada, $general, 'Closed', 'no', 0, false, false), $this->stringField($expected, 'add_denied'));

        $project->status = Project::STATUS_ARCHIVED;
        $project->save();
        $this->rejected(fn () => $boards->index($ada, $project->fresh() ?? $project), $this->stringField($expected, 'module_denied'));
        $this->rejected(fn () => $boards->index($this->user('admin'), $project->fresh() ?? $project), $this->stringField($expected, 'module_denied'));

        $project->status = Project::STATUS_ACTIVE;
        $project->save();
        EnabledModule::query()->where('project_id', $project->id)->where('name', 'boards')->delete();
        $this->rejected(fn () => $boards->index($ada, $project->fresh() ?? $project), $this->stringField($expected, 'module_denied'));
        $this->rejected(fn () => $boards->index($this->user('admin'), $project->fresh() ?? $project), $this->stringField($expected, 'module_denied'));
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/BoardsParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/boards/messages.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/boards/messages.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function enable(string $name): void
    {
        EnabledModule::query()->create(['project_id' => 1, 'name' => $name]);
    }

    /**
     * @param  list<string>  $names
     */
    private function grant(int $roleId, array $names): void
    {
        $role = Role::query()->findOrFail($roleId);
        $permissions = $role->permissions;
        $this->assertIsArray($permissions);
        $role->permissions = array_values(array_unique([...$permissions, ...$names]));
        $role->save();
    }

    private function project(): Project
    {
        $project = Project::query()->where('identifier', 'parity-core')->first();
        $this->assertInstanceOf(Project::class, $project);

        return $project;
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
            $this->fail('Expected '.$message);
        } catch (DomainException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $this->assertIsString($value, $key);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->assertIsInt($value, $key);

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function zipEntries(string|false $contents): array
    {
        $this->assertIsString($contents);
        $path = tempnam(sys_get_temp_dir(), 'board-zip');
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
}
