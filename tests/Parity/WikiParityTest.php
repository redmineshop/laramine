<?php

namespace Tests\Parity;

use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\DomainException;
use App\Domain\Wiki\WikiService;
use App\Models\Attachment;
use App\Models\EnabledModule;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WikiContent;
use App\Models\WikiContentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;
use ZipArchive;

/**
 * Compares wiki pages, versions, redirects, and the wiki page zip to the pin.
 *
 * Textile and Markdown are not rendered. Export text stays raw.
 */
class WikiParityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pages_versions_redirects_and_the_zip_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $this->enable('wiki');
        $this->grant(1, [
            'view_wiki_pages',
            'view_wiki_edits',
            'export_wiki_pages',
            'edit_wiki_pages',
            'view_wiki_page_watchers',
            'add_wiki_page_watchers',
        ]);
        $this->grant(2, [
            'view_wiki_pages',
            'view_wiki_edits',
            'export_wiki_pages',
            'edit_wiki_pages',
            'rename_wiki_pages',
            'delete_wiki_pages',
            'delete_wiki_pages_attachments',
            'protect_wiki_pages',
            'manage_wiki',
            'view_wiki_page_watchers',
            'add_wiki_page_watchers',
            'delete_wiki_page_watchers',
        ]);
        $this->grant(7, ['view_wiki_pages', 'view_messages']);

        Carbon::setTestNow('2026-10-07 12:00:00');
        $wiki = app(WikiService::class);
        $project = $this->project();
        $ada = $this->user('ada');
        $bea = $this->user('bea');
        $this->rejected(fn () => $wiki->createPage($ada, $project, '   ', 'x', '', null, false, false), $this->stringField($expected, 'empty_title'));

        $page = $wiki->createPage($ada, $project, $this->stringField($expected, 'title_input'), $this->stringField($expected, 'text'), 'first', null, false, false);
        $this->assertSame($this->stringField($expected, 'title'), $page->title);
        $this->assertSame($this->stringField($expected, 'start_page'), (string) $page->wiki()->firstOrFail()->start_page);

        Carbon::setTestNow('2026-10-07 13:00:00');
        $wiki->updateContent($ada, $page, $this->stringField($expected, 'updated_text'), 'second', false);
        $content = WikiContent::query()->where('page_id', $page->id)->first();
        $this->assertInstanceOf(WikiContent::class, $content);
        $this->assertSame(2, (int) $content->version);
        $wiki->updateContent($ada, $page, $this->stringField($expected, 'updated_text'), 'second', false);
        $this->assertSame(2, WikiContentVersion::query()->where('page_id', $page->id)->count());

        $this->assertSame($expected['diff'], $wiki->diff($ada, $page, 1, 2));
        $annotate = [];
        foreach ($wiki->annotate($ada, $page) as $line) {
            $annotate[] = ['line' => $line['line'], 'version' => $line['version']];
        }
        $this->assertSame($expected['annotate'], $annotate);
        $history = $wiki->history($ada, $page);
        $this->assertSame('gzip', $history[0]['compression']);
        $this->assertSame($this->stringField($expected, 'text'), $history[0]['text']);
        $this->assertSame($this->stringField($expected, 'updated_text'), $history[1]['text']);

        $export = $wiki->export($ada, $page);
        $this->assertSame($this->stringField($expected, 'updated_text'), $export['text']);
        $this->assertStringNotContainsString($this->stringField($expected, 'export_omits'), $export['text']);

        $restored = $wiki->deleteVersion($bea, $page, 2);
        $this->assertSame($this->stringField($expected, 'text'), $restored->text);
        $this->assertSame(1, (int) $restored->version);
        $this->rejected(fn () => $wiki->deleteVersion($bea, $page, 1), $this->stringField($expected, 'only_version'));
        $wiki->updateContent($ada, $page, $this->stringField($expected, 'updated_text'), 'again', false);
        $wiki->deleteVersion($bea, $page, 1);
        $this->assertSame(2, (int) $page->content()->firstOrFail()->version);
        $this->assertSame([$this->stringField($expected, 'updated_text')], array_column($wiki->history($ada, $page), 'text'));

        $journals = Journal::query()->count();
        $container = app(AttachmentContainerService::class);
        $empty = $this->actingAs($ada)->getJson('/attachments/wiki_pages/'.$page->id.'/download');
        $empty->assertNotFound();
        $empty->assertJsonPath('message', $this->stringField($expected, 'empty_message'));
        $missing = $this->actingAs($ada)->getJson('/attachments/wiki_pages/999999/download');
        $missing->assertNotFound();
        $missing->assertJsonPath('message', $this->stringField($expected, 'missing_message'));

        $first = $container->upload($ada, 'note.txt', 'alpha', 'text/plain');
        $second = $container->upload($ada, 'note.txt', 'beta', 'text/plain');
        $kept = $wiki->attach($ada, $page, $first['token'], 'note.txt', null);
        $wiki->attach($ada, $page, $second['token'], 'note.txt', null);
        $this->assertSame($journals, Journal::query()->count());
        $zip = $this->actingAs($ada)->get('/attachments/wiki_pages/'.$page->id.'/download');
        $zip->assertOk();
        $this->assertStringContainsString('wiki_page-'.$page->id.'-attachments.zip', (string) $zip->headers->get('content-disposition'));
        $this->assertSame($expected['zip_entries'], $this->zipEntries($zip->getContent()));
        $download = $this->actingAs($this->user('finn'))->get('/attachments/'.$kept->id);
        $download->assertOk();
        $this->assertSame('alpha', $download->streamedContent());
        $kept->refresh();
        $this->assertSame($this->intField($expected, 'downloads'), (int) $kept->downloads);

        $this->rejected(fn () => $wiki->deleteAttachment($ada, $kept), $this->stringField($expected, 'attachment_denied'));
        $this->actingAs($ada)->deleteJson('/attachments/'.$kept->id)->assertForbidden();
        $this->actingAs($bea)->delete('/attachments/'.$kept->id)->assertNoContent();
        $this->assertNull(Attachment::query()->find($kept->id));

        $wiki->protect($bea, $page, true);
        $this->rejected(fn () => $wiki->updateContent($ada, $page, "locked\n", 'no', false), $this->stringField($expected, 'protect_denied'));
        $wiki->updateContent($bea, $page, "kept\n", 'yes', false);
        $renames = $expected['renames'];
        $this->assertIsArray($renames);
        $current = $page;
        foreach ($renames as $title) {
            $this->assertIsString($title);
            $current = $wiki->rename($bea, $current, $title);
        }
        $redirects = [];
        foreach ($wiki->redirectRows($ada, $project) as $row) {
            $this->assertSame((int) $current->wiki_id, $row['redirects_to_wiki_id']);
            $redirects[] = ['title' => $row['title'], 'redirects_to' => $row['redirects_to']];
        }
        $this->assertSame($expected['redirects'], $redirects);
        $found = $wiki->find($ada, $project, 'cook_book');
        $this->assertTrue($found['redirected']);
        $this->assertSame('Pantry', $found['title']);
        $this->assertSame('Cook book', $found['from']);

        $child = $wiki->createPage($ada, $project, 'Recipes', "child\n", '', (int) $current->id, false, false);
        $this->assertSame((int) $current->id, (int) $child->parent_id);
        $this->rejected(fn () => $wiki->move($bea, $current, (int) $child->id), $this->stringField($expected, 'cycle'));
        $wiki->deletePage($bea, $current);
        $this->assertNull($child->fresh()?->parent_id);

        $this->rejected(fn () => $wiki->history($this->user('finn'), $child), $this->stringField($expected, 'history_denied'));
        $this->rejected(fn () => $wiki->export($this->user('finn'), $child), $this->stringField($expected, 'export_denied'));
        $this->actingAs($this->user('cleo'))->getJson('/attachments/wiki_pages/'.$child->id.'/download')->assertForbidden();

        $project->status = Project::STATUS_CLOSED;
        $project->save();
        $this->assertSame('Recipes', $wiki->find($ada, $project->fresh() ?? $project, 'Recipes')['title']);
        $this->rejected(fn () => $wiki->updateContent($this->user('admin'), $child, "closed\n", 'no', false), $this->stringField($expected, 'edit_denied'));

        $project->status = Project::STATUS_ARCHIVED;
        $project->save();
        $this->rejected(fn () => $wiki->find($ada, $project->fresh() ?? $project, 'Recipes'), $this->stringField($expected, 'module_denied'));
        $this->rejected(fn () => $wiki->find($this->user('admin'), $project->fresh() ?? $project, 'Recipes'), $this->stringField($expected, 'module_denied'));

        $project->status = Project::STATUS_ACTIVE;
        $project->save();
        EnabledModule::query()->where('project_id', $project->id)->where('name', 'wiki')->delete();
        $this->rejected(fn () => $wiki->find($ada, $project->fresh() ?? $project, 'Recipes'), $this->stringField($expected, 'module_denied'));
        $this->rejected(fn () => $wiki->find($this->user('admin'), $project->fresh() ?? $project, 'Recipes'), $this->stringField($expected, 'module_denied'));
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/WikiParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/wiki/pages.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Textile and Markdown rendering \| NOT VERIFIED \|/m', $checklist);
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/wiki/pages.json';
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
        $path = tempnam(sys_get_temp_dir(), 'wiki-zip');
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
