<?php

namespace Tests\Parity;

use App\Domain\Settings\SettingValue;
use App\Http\Controllers\WikiController;
use App\Models\EnabledModule;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\WikiRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use JsonException;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares the wiki HTTP surface to the pin.
 *
 * The service row stays on WikiParityTest. These pages are not a Redmine screen.
 */
class WikiHttpParityTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "h1. Guide\n\nh2. Details\n\n*word*\n\n{{toc}}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_http_routes_acl_redirects_and_rendered_content_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->enable('wiki');
        $this->textile();
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
        $this->grant(7, ['view_wiki_pages']);

        $ada = $this->user('ada');
        $bea = $this->user('bea');
        $finn = $this->user('finn');
        $admin = $this->user('admin');
        $project = $this->project();

        $guest = $this->get('/projects/parity-core/wiki/index');
        $guestJson = $this->getJson('/projects/parity-core/wiki/index');
        $finnNew = $this->actingAs($finn)->get('/projects/parity-core/wiki/new');
        $finnMissing = $this->actingAs($finn)->get('/projects/parity-core/wiki/Missing');
        $adaMissing = $this->actingAs($ada)->get('/projects/parity-core/wiki/Missing');
        $adaStart = $this->actingAs($ada)->get('/projects/parity-core/wiki');

        EnabledModule::query()->where('project_id', $project->id)->where('name', 'wiki')->delete();
        $moduleOff = $this->actingAs($admin)->get('/projects/parity-core/wiki/index');
        $this->enable('wiki');

        $created = $this->actingAs($ada)->post('/projects/parity-core/wiki/new', [
            'title' => 'guide',
            'text' => self::TEXT,
            'comments' => 'first',
        ]);
        $emptyTitle = $this->actingAs($ada)->postJson('/projects/parity-core/wiki/new', [
            'title' => '   ',
            'text' => 'x',
        ]);
        $show = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide');
        $showProps = $this->props($show);
        $page = $this->arrayProp($showProps, 'page');

        $updated = $this->actingAs($ada)->put('/projects/parity-core/wiki/Guide', [
            'text' => self::TEXT."\nsecond\n",
            'comments' => 'second',
        ]);
        $conflict = $this->actingAs($ada)->put('/projects/parity-core/wiki/Guide', [
            'text' => "h1. Guide\n\noverwrite\n",
            'comments' => 'stale',
            'version' => 1,
        ]);
        $conflictProps = $this->props($conflict);
        $afterConflict = WikiPage::query()->where('title', 'Guide')->first();
        $this->assertInstanceOf(WikiPage::class, $afterConflict);
        $storedVersion = (int) $afterConflict->content()->firstOrFail()->version;

        $old = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide/1');
        $oldDenied = $this->actingAs($finn)->get('/projects/parity-core/wiki/Guide/1');
        $section = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide/edit?section=2');
        $sectionProps = $this->props($section);
        $sectionHash = $sectionProps['sectionHash'] ?? null;
        $this->assertIsString($sectionHash);
        $sectionVersion = $sectionProps['version'] ?? null;
        $this->assertIsInt($sectionVersion);
        $sectionSaved = $this->actingAs($ada)->put('/projects/parity-core/wiki/Guide', [
            'text' => "h2. Notes\n\nplain\n",
            'comments' => 'section',
            'version' => $sectionVersion,
            'section' => 2,
            'section_hash' => $sectionHash,
        ]);
        $staleSection = $this->actingAs($ada)->put('/projects/parity-core/wiki/Guide', [
            'text' => "h2. Other\n",
            'comments' => 'stale-section',
            'version' => $sectionVersion + 1,
            'section' => 2,
            'section_hash' => str_repeat('0', 40),
        ]);
        $afterSection = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide');

        $history = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide/history');
        $historyDenied = $this->actingAs($finn)->get('/projects/parity-core/wiki/Guide/history');
        $diff = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide/diff?version=2&version_from=1');
        $annotate = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide/annotate');
        $exportDenied = $this->actingAs($finn)->get('/projects/parity-core/wiki/Guide.txt');
        $txt = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide.txt');
        $html = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide.html');
        $pdf = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide.pdf');
        $png = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide.png');
        $wikiTxt = $this->actingAs($ada)->get('/projects/parity-core/wiki/export.txt');
        $wikiHtml = $this->actingAs($ada)->get('/projects/parity-core/wiki/export.html');
        $wikiPdf = $this->actingAs($ada)->get('/projects/parity-core/wiki/export.pdf');
        $wikiPng = $this->actingAs($ada)->get('/projects/parity-core/wiki/export.png');
        $previewDenied = $this->actingAs($finn)->post('/projects/parity-core/wiki/Guide/preview', ['text' => '*word*']);
        $preview = $this->actingAs($ada)->post('/projects/parity-core/wiki/Guide/preview', ['text' => '*word*']);

        $upload = $this->actingAs($ada)->call(
            'POST',
            '/attachments/upload?filename=note.txt&content_type=text/plain',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'alpha',
        );
        $token = $upload->json('token');
        $this->assertIsString($token);
        $attached = $this->actingAs($ada)->post('/projects/parity-core/wiki/Guide/add_attachment', [
            'token' => $token,
            'filename' => 'note.txt',
        ]);
        $withFile = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide');

        $watched = $this->actingAs($ada)->from('/projects/parity-core/wiki/Guide')->post('/watchers/watch', [
            'object_type' => 'wiki_page',
            'object_id' => (int) $afterConflict->id,
        ]);
        $addedWatcher = $this->actingAs($bea)->from('/projects/parity-core/wiki/Guide')->post('/watchers', [
            'object_type' => 'wiki_page',
            'object_id' => (int) $afterConflict->id,
            'user_id' => (int) $finn->id,
        ]);
        $watchers = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide');
        $hiddenWatchers = $this->actingAs($finn)->get('/projects/parity-core/wiki/Guide');

        $protected = $this->actingAs($bea)->post('/projects/parity-core/wiki/Guide/protect', ['protected' => '1']);
        $protectedDenied = $this->actingAs($ada)->put('/projects/parity-core/wiki/Guide', [
            'text' => "h1. Guide\n\nlocked\n",
            'comments' => 'no',
        ]);
        $this->actingAs($bea)->post('/projects/parity-core/wiki/Guide/protect', ['protected' => '0']);

        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', [
            'title' => 'Cook book',
            'text' => "pantry\n",
        ]);
        $renamed = $this->actingAs($bea)->post('/projects/parity-core/wiki/Cook_book/rename', [
            'title' => 'Pantry',
        ]);
        $hop = $this->actingAs($finn)->get('/projects/parity-core/wiki/Cook_book');
        $renamedAgain = $this->actingAs($bea)->post('/projects/parity-core/wiki/Pantry/rename', [
            'title' => 'Shelf',
            'redirect' => '0',
        ]);
        $noHop = $this->actingAs($finn)->get('/projects/parity-core/wiki/Pantry');
        $editableMiss = $this->actingAs($ada)->get('/projects/parity-core/wiki/Pantry');
        $redirectRows = WikiRedirect::query()->orderBy('title')->pluck('title')->all();

        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', [
            'title' => 'Parent',
            'text' => "parent\n",
        ]);
        $parent = WikiPage::query()->where('title', 'Parent')->first();
        $this->assertInstanceOf(WikiPage::class, $parent);
        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', [
            'title' => 'Child',
            'text' => "child\n",
            'parent_id' => (int) $parent->id,
        ]);
        $confirm = $this->actingAs($bea)->delete('/projects/parity-core/wiki/Parent');
        $nullified = $this->actingAs($bea)->delete('/projects/parity-core/wiki/Parent', ['todo' => 'nullify']);
        $child = WikiPage::query()->where('title', 'Child')->first();
        $this->assertInstanceOf(WikiPage::class, $child);
        $childParent = $child->parent_id === null ? null : (int) $child->parent_id;

        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', ['title' => 'Trunk', 'text' => "t\n"]);
        $trunk = WikiPage::query()->where('title', 'Trunk')->first();
        $this->assertInstanceOf(WikiPage::class, $trunk);
        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', [
            'title' => 'Leaf',
            'text' => "l\n",
            'parent_id' => (int) $trunk->id,
        ]);
        $destroyed = $this->actingAs($bea)->delete('/projects/parity-core/wiki/Trunk', ['todo' => 'destroy']);
        $leafGone = $this->actingAs($finn)->get('/projects/parity-core/wiki/Leaf');

        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', ['title' => 'Alpha', 'text' => "a\n"]);
        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', ['title' => 'Gamma', 'text' => "g\n"]);
        $alpha = WikiPage::query()->where('title', 'Alpha')->first();
        $gamma = WikiPage::query()->where('title', 'Gamma')->first();
        $this->assertInstanceOf(WikiPage::class, $alpha);
        $this->assertInstanceOf(WikiPage::class, $gamma);
        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', [
            'title' => 'Beta',
            'text' => "b\n",
            'parent_id' => (int) $alpha->id,
        ]);
        $reassigned = $this->actingAs($bea)->delete('/projects/parity-core/wiki/Alpha', [
            'todo' => 'reassign',
            'reassign_to_id' => (int) $gamma->id,
        ]);
        $beta = WikiPage::query()->where('title', 'Beta')->first();
        $this->assertInstanceOf(WikiPage::class, $beta);
        $betaParent = $beta->parent_id === null ? null : (int) $beta->parent_id;

        $this->actingAs($ada)->post('/projects/parity-core/wiki/new', ['title' => 'Scratch', 'text' => "one\n"]);
        $this->actingAs($ada)->put('/projects/parity-core/wiki/Scratch', [
            'text' => "two\n",
            'comments' => 'two',
        ]);
        $versionDenied = $this->actingAs($ada)->deleteJson('/projects/parity-core/wiki/Scratch/2');
        $versionGone = $this->actingAs($bea)->delete('/projects/parity-core/wiki/Scratch/2');
        $onlyVersion = $this->actingAs($bea)->deleteJson('/projects/parity-core/wiki/Scratch/1');

        $start = $this->actingAs($bea)->put('/projects/parity-core/wiki', ['start_page' => 'Shelf']);
        $startShow = $this->actingAs($finn)->get('/projects/parity-core/wiki');
        $dateIndex = $this->actingAs($ada)->get('/projects/parity-core/wiki/date_index');

        $project->status = Project::STATUS_CLOSED;
        $project->save();
        $closedRead = $this->actingAs($ada)->get('/projects/parity-core/wiki/Guide');
        $closedWrite = $this->actingAs($admin)->put('/projects/parity-core/wiki/Guide', [
            'text' => "closed\n",
            'comments' => 'no',
        ]);
        $project->status = Project::STATUS_ARCHIVED;
        $project->save();
        $archived = $this->actingAs($admin)->get('/projects/parity-core/wiki/Guide');
        $project->status = Project::STATUS_ACTIVE;
        $project->save();

        $removedWiki = $this->actingAs($bea)->delete('/projects/parity-core/wiki');
        EnabledModule::query()->where('project_id', $project->id)->where('name', 'wiki')->delete();
        $disabled = $this->actingAs($admin)->get('/projects/parity-core/wiki/index');

        $actual = [
            'routes' => $this->routes('wiki'),
            'guest_html' => $guest->status(),
            'guest_location' => $this->location($guest),
            'guest_json' => $guestJson->status(),
            'finn_new' => $finnNew->status(),
            'finn_missing' => $finnMissing->status(),
            'ada_missing' => $adaMissing->status(),
            'ada_missing_location' => $this->location($adaMissing),
            'ada_start' => $adaStart->status(),
            'ada_start_location' => $this->location($adaStart),
            'module_off_admin' => $moduleOff->status(),
            'empty_title' => $emptyTitle->status(),
            'empty_title_message' => $emptyTitle->json('message'),
            'created' => $created->status(),
            'created_location' => $this->location($created),
            'show_component' => $this->pageComponent($show),
            'show_title' => $page['title'] ?? null,
            'show_html' => $showProps['html'] ?? null,
            'updated' => $updated->status(),
            'conflict' => $conflict->status(),
            'conflict_component' => $this->pageComponent($conflict),
            'conflict_message' => $conflictProps['conflict'] ?? null,
            'stored_version_after_conflict' => $storedVersion,
            'old_version_component' => $this->pageComponent($old),
            'old_version_text' => $this->arrayProp($this->props($old), 'page')['text'] ?? null,
            'old_version_denied' => $oldDenied->status(),
            'section_saved' => $sectionSaved->status(),
            'stale_section' => $staleSection->status(),
            'stale_section_message' => $this->props($staleSection)['conflict'] ?? null,
            'after_section_html' => $this->props($afterSection)['html'] ?? null,
            'history_component' => $this->pageComponent($history),
            'history_comments' => array_column($this->listProp($this->props($history), 'versions'), 'comments'),
            'history_denied' => $historyDenied->status(),
            'diff_ops' => $this->props($diff)['ops'] ?? null,
            'annotate_lines' => $this->annotateLines($this->props($annotate)),
            'export_denied' => $exportDenied->status(),
            'txt' => $txt->getContent(),
            'html_export' => $html->getContent(),
            'pdf_prefix' => substr($pdf->getContent(), 0, 5),
            'pdf_has_title' => str_contains($pdf->getContent(), 'Guide'),
            'png' => [
                'status' => $png->status(),
                'body' => $png->getContent(),
                'citation' => 'Core wiki export formats without an extra gem are html, txt, and pdf. PNG is the optional mini_magick gantt export, not a wiki page format.',
            ],
            'wiki_txt_has_guide' => str_contains($wikiTxt->getContent(), 'Guide'),
            'wiki_html_has_toc' => str_contains($wikiHtml->getContent(), 'class="toc left"'),
            'wiki_pdf_prefix' => substr($wikiPdf->getContent(), 0, 5),
            'wiki_png_status' => $wikiPng->status(),
            'wiki_png_body' => $wikiPng->getContent(),
            'preview_denied' => $previewDenied->status(),
            'preview' => $preview->getContent(),
            'attached' => $attached->status(),
            'attachment_names' => array_column($this->listProp($this->props($withFile), 'attachments'), 'filename'),
            'watched' => $watched->status(),
            'added_watcher' => $addedWatcher->status(),
            'watcher_ids' => $this->props($watchers)['watcherIds'] ?? null,
            'watching' => $this->props($watchers)['watching'] ?? null,
            'finn_watcher_ids' => $this->props($hiddenWatchers)['watcherIds'] ?? null,
            'protected' => $protected->status(),
            'protected_denied' => $protectedDenied->status(),
            'renamed' => $renamed->status(),
            'renamed_location' => $this->location($renamed),
            'hop' => $hop->status(),
            'hop_location' => $this->location($hop),
            'renamed_without_redirect' => $renamedAgain->status(),
            'no_hop' => $noHop->status(),
            'editable_miss' => $editableMiss->status(),
            'editable_miss_location' => $this->location($editableMiss),
            'redirect_titles' => $redirectRows,
            'destroy_confirm' => $confirm->status(),
            'destroy_confirm_component' => $this->pageComponent($confirm),
            'nullified' => $nullified->status(),
            'child_parent_after_nullify' => $childParent,
            'destroyed_tree' => $destroyed->status(),
            'leaf_after_destroy' => $leafGone->status(),
            'reassigned' => $reassigned->status(),
            'beta_parent_is_gamma' => $betaParent === (int) $gamma->id,
            'version_denied' => $versionDenied->status(),
            'version_gone' => $versionGone->status(),
            'only_version' => $onlyVersion->status(),
            'only_version_message' => $onlyVersion->json('message'),
            'start' => $start->status(),
            'start_title' => $this->arrayProp($this->props($startShow), 'page')['title'] ?? null,
            'date_index_mode' => $this->props($dateIndex)['mode'] ?? null,
            'closed_read' => $closedRead->status(),
            'closed_write' => $closedWrite->status(),
            'archived_admin' => $archived->status(),
            'removed_wiki' => $removedWiki->status(),
            'disabled_admin' => $disabled->status(),
            'unsupported_export' => WikiController::UNSUPPORTED_EXPORT,
        ];

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/wiki/http.json', $actual);
    }

    public function test_checklist_keeps_verified_rows_and_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki and boards visual UX \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Queries \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Textile and Markdown rendering \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Calendar and Gantt \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/WikiHttpParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/wiki/http.json', $checklist);
        $this->assertStringContainsString('tests/Parity/QueryRemainderParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/remainder.json', $checklist);
        $this->assertStringContainsString('tests/Parity/MarkupParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/CalendarGanttQueryParityTest.php', $checklist);
    }

    /**
     * @return list<string>
     */
    private function routes(string $needle): array
    {
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains($uri, '/'.$needle)) {
                continue;
            }
            $methods = array_values(array_filter(
                $route->methods(),
                static fn (string $method): bool => $method !== 'HEAD',
            ));
            $rows[] = implode('|', $methods).' '.$uri;
        }
        sort($rows);

        return $rows;
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

    private function textile(): void
    {
        Setting::query()->create([
            'name' => SettingValue::TEXT_FORMATTING,
            'value' => 'textile',
            'updated_on' => '2026-10-07 12:00:00',
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $props = $response->inertiaProps();
        $this->assertIsArray($props);

        /** @var array<string, mixed> $props */
        return $props;
    }

    private function pageComponent(TestResponse $response): string
    {
        $page = $response->inertiaPage();
        $this->assertIsArray($page);
        $component = $page['component'] ?? null;
        $this->assertIsString($component);

        return $component;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function arrayProp(array $props, string $key): array
    {
        $value = $props[$key] ?? null;
        $this->assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function listProp(array $props, string $key): array
    {
        $value = $props[$key] ?? null;
        $this->assertIsArray($value);
        $rows = [];
        foreach ($value as $row) {
            $this->assertIsArray($row);
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array{line: mixed, version: mixed}>
     */
    private function annotateLines(array $props): array
    {
        $lines = $props['lines'] ?? null;
        $this->assertIsArray($lines);
        $rows = [];
        foreach ($lines as $line) {
            $this->assertIsArray($line);
            $rows[] = ['line' => $line['line'] ?? null, 'version' => $line['version'] ?? null];
        }

        return $rows;
    }

    private function location(TestResponse $response): string
    {
        $header = $response->headers->get('Location');
        $this->assertIsString($header);
        $path = parse_url($header, PHP_URL_PATH);
        $query = parse_url($header, PHP_URL_QUERY);
        $this->assertIsString($path);

        return is_string($query) && $query !== '' ? $path.'?'.$query : $path;
    }

    private function assertPinned(string $path, mixed $actual): void
    {
        $full = base_path($path);
        try {
            $encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage());
        }

        if (getenv('LARAMINE_RECORD') === '1') {
            file_put_contents($full, $encoded);
        }

        $stored = file_get_contents($full);
        $this->assertIsString($stored);
        try {
            $expected = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage());
        }

        $this->assertSame($expected, $actual);
    }
}
