<?php

namespace Tests\Parity;

use App\Domain\Boards\MessageService;
use App\Domain\Documents\DocumentService;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\News\NewsService;
use App\Domain\Notifications\IssueNotifier;
use App\Domain\Settings\SettingValue;
use App\Domain\TextFormatting\FormattingContext;
use App\Domain\TextFormatting\TextFormatter;
use App\Domain\Wiki\WikiService;
use App\Mail\RedmineNotificationMail;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Document;
use App\Models\EnabledModule;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wiki;
use App\Models\WikiContent;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares textile, CommonMark, and plain rendering to the 7.0.1-shaped pin.
 *
 * Repository, changeset, and source links stay text. The pin excludes those tables.
 */
class MarkupParityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_common_mark_html_matches_the_pin(): void
    {
        $this->prepare();
        $this->compare('common_mark');
    }

    public function test_textile_html_matches_the_pin(): void
    {
        $this->prepare();
        $this->compare('textile');
    }

    public function test_plain_html_matches_the_pin(): void
    {
        $this->prepare();
        $this->compare('plain');
    }

    public function test_text_formatting_setting_selects_the_formatter(): void
    {
        $this->prepare();
        $settings = app(SettingValue::class);
        $this->assertSame('common_mark', $settings->textFormatting());

        Setting::query()->create([
            'name' => SettingValue::TEXT_FORMATTING,
            'value' => 'textile',
            'updated_on' => '2026-10-07 12:00:00',
        ]);
        $this->assertSame('textile', $settings->textFormatting());

        Setting::query()->where('name', SettingValue::TEXT_FORMATTING)->update(['value' => '']);
        $this->assertSame('', $settings->textFormatting());

        Setting::query()->where('name', SettingValue::TEXT_FORMATTING)->update(['value' => 'markdown']);
        $this->assertSame('', $settings->textFormatting());

        Setting::query()->where('name', SettingValue::TEXT_FORMATTING)->delete();
        $this->assertSame('common_mark', $settings->textFormatting());
    }

    public function test_rendered_surfaces_use_the_pin_html(): void
    {
        $this->prepare();
        $this->grant(1, [
            'view_wiki_pages',
            'export_wiki_pages',
            'edit_wiki_pages',
            'view_news',
            'view_documents',
            'view_messages',
        ]);
        foreach (['wiki', 'news', 'documents', 'boards'] as $module) {
            EnabledModule::query()->create(['project_id' => 1, 'name' => $module]);
        }
        $expected = $this->expectation('common_mark');
        $cases = $expected['cases'];
        $this->assertIsArray($cases);
        $emphasis = $cases['emphasis']['html'] ?? null;
        $headings = $cases['headings']['html'] ?? null;
        $this->assertIsString($emphasis);
        $this->assertIsString($headings);

        $ada = User::query()->where('login', 'ada')->first();
        $this->assertInstanceOf(User::class, $ada);
        $issue = Issue::query()->findOrFail(1);
        $this->assertInstanceOf(Issue::class, $issue);
        $issue->description = 'Shipped with *emphasis* in the note.';
        $issue->save();
        $journal = Journal::query()->findOrFail(1);
        $this->assertInstanceOf(Journal::class, $journal);
        $journal->notes = 'Shipped with *emphasis* in the note.';
        $journal->save();
        $view = app(IssueHistoryPresenter::class)->present($ada, $issue->fresh() ?? $issue);
        $this->assertSame($emphasis, $view->descriptionHtml);
        $noteHtml = null;
        foreach ($view->historyEntries as $entry) {
            if ($entry->journalId === 1) {
                $noteHtml = $entry->noteHtml;
            }
        }
        $this->assertSame($emphasis, $noteHtml);

        $page = WikiPage::query()->where('title', 'Guide')->first();
        $this->assertInstanceOf(WikiPage::class, $page);
        $content = WikiContent::query()->where('page_id', $page->id)->first();
        $this->assertInstanceOf(WikiContent::class, $content);
        $content->text = "# Guide\n\n## Details\n\n{{toc}}\n\n{{>toc}}";
        $content->save();
        $project = Project::query()->findOrFail(1);
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame($headings, app(WikiService::class)->html($ada, $page));
        $export = app(WikiService::class)->export($ada, $page);
        $this->assertSame($content->text, $export['text']);
        $this->assertStringNotContainsString('<h1', $export['text']);

        $news = News::query()->where('title', 'Greetings')->first();
        $this->assertInstanceOf(News::class, $news);
        $news->description = 'Shipped with *emphasis* in the note.';
        $news->save();
        $this->assertSame($emphasis, app(NewsService::class)->html($ada, $news));

        $document = Document::query()->where('title', 'Spec')->first();
        $this->assertInstanceOf(Document::class, $document);
        $document->description = 'Shipped with *emphasis* in the note.';
        $document->save();
        $this->assertSame($emphasis, app(DocumentService::class)->html($ada, $document));

        $message = Message::query()->where('subject', 'Hello')->first();
        $this->assertInstanceOf(Message::class, $message);
        $message->content = 'Shipped with *emphasis* in the note.';
        $message->save();
        $this->assertSame($emphasis, app(MessageService::class)->html($ada, $message));

        $issue->description = 'See #3';
        $issue->save();
        Mail::fake();
        app(IssueNotifier::class)->added($ada, $issue->fresh() ?? $issue);
        $html = null;
        $text = null;
        Mail::assertQueued(RedmineNotificationMail::class, function (RedmineNotificationMail $mail) use (&$html, &$text): bool {
            $html = $mail->bodyHtml;
            $text = $mail->bodyText;

            return true;
        });
        $this->assertIsString($html);
        $this->assertIsString($text);
        $this->assertStringContainsString('<div class="wiki">', $html);
        $this->assertStringContainsString('http://localhost:3000/issues/3', $html);
        $this->assertStringContainsString(' closed"', $html);
        $this->assertStringNotContainsString('<a ', $text);
        $this->assertStringNotContainsString('<h1>', $text);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Textile and Markdown rendering \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/MarkupParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/markup/common_mark.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/markup/textile.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/markup/plain.json', $checklist);
        $this->assertMatchesRegularExpression('/^\| Calendar and Gantt \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
    }

    private function prepare(): void
    {
        Redmine701Fixture::load();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->seedTargets();
    }

    private function compare(string $format): void
    {
        $actual = [];
        foreach ($this->cases($format) as $name => $case) {
            $actual[$name] = $this->normalize($this->render($format === 'plain' ? '' : $format, $case['input'], $case['context']));
        }
        if (getenv('LARAMINE_DUMP_MARKUP') === '1') {
            $dumped = [];
            foreach ($this->cases($format) as $name => $case) {
                $dumped[$name] = [
                    'input' => $case['input'],
                    'context' => $case['context'],
                    'html' => $actual[$name],
                ];
            }
            file_put_contents('/tmp/markup-'.$format.'.json', (string) json_encode([
                'format' => $format === 'plain' ? '' : $format,
                'repository_links' => [
                    'status' => 'N/A',
                    'citation' => 'tests/Parity/fixtures/redmine-7.0.1/manifest.json excludes repositories, changesets, changes, changeset_parents, and changesets_issues. rN, commit:, source:, and export: stay plain text.',
                ],
                'cases' => $dumped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->assertNotSame('', $actual['emphasis']);

            return;
        }
        $expected = $this->expectation($format);
        $this->assertSame('N/A', $expected['repository_links']['status'] ?? null);
        $this->assertStringContainsString('manifest.json', (string) ($expected['repository_links']['citation'] ?? ''));
        $cases = $expected['cases'] ?? null;
        $this->assertIsArray($cases);
        $rendered = [];
        foreach ($cases as $name => $case) {
            $this->assertIsArray($case);
            $input = $case['input'] ?? null;
            $context = $case['context'] ?? null;
            $html = $case['html'] ?? null;
            $this->assertIsString($input);
            $this->assertIsString($context);
            $this->assertIsString($html);
            $rendered[$name] = $html;
            $this->assertSame($html, $this->normalize($this->render($format === 'plain' ? '' : $format, $input, $context)), (string) $name);
        }
        $this->assertSame($rendered, $actual);
        $sample = $actual['repository'] ?? '';
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*(?:repositories|changesets|source:|export:|commit:)/', $sample);
        $this->assertStringContainsString('r758', $sample);
        $this->assertStringContainsString('commit:c6f4d0fd', $sample);
        $this->assertStringContainsString('source:some/file', $sample);
        $this->assertStringContainsString('export:some/file', $sample);
    }

    /**
     * @return array<string, array{input: string, context: string}>
     */
    private function cases(string $format): array
    {
        $shared = [
            'wiki_links' => ['input' => '[[Guide]] [[Missing]] [[parity-child:Guide|Child label]] [[#Details]] [[parity-core:]]', 'context' => 'wiki'],
            'issues' => ['input' => '#1 #3 #1#note-2 #1-2 ##1 #note-2 Bug#1 !#1', 'context' => 'issue-ada'],
            'resources' => ['input' => 'version#1 version:"1.0" document#{document} document:Spec news#{news} news:Greetings message#{topic} message#{reply} project#1 project:parity-core user#1 user:ada @lars forum#{board} forum:Discussion attachment:before.pdf', 'context' => 'issue'],
            'scoped' => ['input' => 'parity-core:version:1.0 parity-core:document:"Spec"', 'context' => 'issue'],
            'thumbnail' => ['input' => '{{thumbnail(picture.png)}} {{thumbnail(picture.png, size=80, title=Shot)}}', 'context' => 'issue'],
            'collapse' => ['input' => "{{collapse(View details...)\nHidden *word*\n}}", 'context' => 'issue'],
            'include' => ['input' => '{{include(Included)}}', 'context' => 'wiki'],
            'children' => ['input' => "{{child_pages}}\n\n{{child_pages(Guide, parent=1)}}", 'context' => 'wiki'],
            'hello' => ['input' => '{{hello_world}} {{hello_world(a, b)}}', 'context' => 'issue'],
            'macros' => ['input' => "{{macro_list}}\n\n{{issue(3)}} {{issue(3, tracker=false)}}\n\n{{recent_pages(time=true)}}", 'context' => 'wiki'],
            'repository' => ['input' => 'r758 commit:c6f4d0fd source:some/file export:some/file parity-core:r758', 'context' => 'issue'],
        ];
        $specific = match ($format) {
            'textile' => [
                'emphasis' => ['input' => '*bold* and _italic_', 'context' => 'issue'],
                'headings' => ['input' => "h1. Guide\n\nh2. Details\n\n{{toc}}\n\n{{>toc}}", 'context' => 'wiki-edit'],
                'image' => ['input' => '!picture.png!', 'context' => 'issue'],
                'code' => ['input' => "<pre><code class=\"ruby\">def greet\n  # hi\n  puts \"ok\"\nend</code></pre>", 'context' => 'issue'],
                'unsafe' => ['input' => '<script>alert(1)</script> <b>ok</b>', 'context' => 'issue'],
            ],
            'plain' => [
                'emphasis' => ['input' => 'Shipped with *emphasis* in the note. <b>', 'context' => 'issue'],
                'breaks' => ['input' => "One\nTwo\n\nhttp://example.test ada@parity.test", 'context' => 'issue'],
                'unsafe' => ['input' => '<script>alert(1)</script>', 'context' => 'issue'],
            ],
            default => [
                'emphasis' => ['input' => 'Shipped with *emphasis* in the note.', 'context' => 'issue'],
                'strong' => ['input' => '**bold** and ~~gone~~', 'context' => 'issue'],
                'headings' => ['input' => "# Guide\n\n## Details\n\n{{toc}}\n\n{{>toc}}", 'context' => 'wiki-edit'],
                'image' => ['input' => '![](picture.png)', 'context' => 'issue'],
                'code' => ['input' => "```ruby\ndef greet\n  # hi\n  puts \"ok\"\nend\n```", 'context' => 'issue'],
                'unsafe' => ['input' => "<script>alert(1)</script>\n\n[click](javascript:alert(1))\n\n<b>ok</b>", 'context' => 'issue'],
                'table' => ['input' => "| A | B |\n| --- | --- |\n| 1 | 2 |\n\n- [ ] task", 'context' => 'issue'],
            ],
        };

        return [...$specific, ...$shared];
    }

    private function render(string $format, string $input, string $contextName): string
    {
        $project = Project::query()->findOrFail(1);
        $this->assertInstanceOf(Project::class, $project);
        $issue = Issue::query()->findOrFail(1);
        $this->assertInstanceOf(Issue::class, $issue);
        $page = WikiPage::query()->where('title', 'Guide')->first();
        $this->assertInstanceOf(WikiPage::class, $page);
        $content = WikiContent::query()->where('page_id', $page->id)->first();
        $this->assertInstanceOf(WikiContent::class, $content);
        $ada = User::query()->where('login', 'ada')->first();
        $this->assertInstanceOf(User::class, $ada);
        $context = match ($contextName) {
            'wiki' => new FormattingContext($project, $content, false, false, null),
            'wiki-edit' => new FormattingContext($project, $content, false, true, $ada),
            'issue-ada' => new FormattingContext($project, $issue, false, false, $ada),
            'absolute' => new FormattingContext($project, $issue, true, false, null),
            default => new FormattingContext($project, $issue, false, false, null),
        };

        return app(TextFormatter::class)->toHtml($this->bind($input), $context, $format === '' ? '' : $format);
    }

    private function bind(string $input): string
    {
        $ids = $this->targetIds();

        return strtr($input, [
            '{document}' => (string) $ids['document'],
            '{news}' => (string) $ids['news'],
            '{topic}' => (string) $ids['topic'],
            '{reply}' => (string) $ids['reply'],
            '{board}' => (string) $ids['board'],
            '{picture}' => (string) $ids['picture'],
        ]);
    }

    /**
     * @return array{document: int, news: int, topic: int, reply: int, board: int, picture: int}
     */
    private function targetIds(): array
    {
        $picture = Attachment::query()->where('filename', 'picture.png')->first();
        $news = News::query()->where('title', 'Greetings')->first();
        $document = Document::query()->where('title', 'Spec')->first();
        $board = Board::query()->where('name', 'Discussion')->first();
        $topic = Message::query()->where('subject', 'Hello')->first();
        $reply = Message::query()->where('subject', 'Reply')->first();
        $this->assertInstanceOf(Attachment::class, $picture);
        $this->assertInstanceOf(News::class, $news);
        $this->assertInstanceOf(Document::class, $document);
        $this->assertInstanceOf(Board::class, $board);
        $this->assertInstanceOf(Message::class, $topic);
        $this->assertInstanceOf(Message::class, $reply);

        return [
            'document' => (int) $document->id,
            'news' => (int) $news->id,
            'topic' => (int) $topic->id,
            'reply' => (int) $reply->id,
            'board' => (int) $board->id,
            'picture' => (int) $picture->id,
        ];
    }

    private function normalize(string $html): string
    {
        $ids = $this->targetIds();
        $replacements = [
            '/attachments/thumbnail/'.$ids['picture'].'/' => '/attachments/thumbnail/{picture}/',
            '/attachments/'.$ids['picture'].'/picture.png' => '/attachments/{picture}/picture.png',
            '/boards/'.$ids['board'].'/topics/'.$ids['topic'].'#message-'.$ids['reply'] => '/boards/{board}/topics/{topic}#message-{reply}',
            '/boards/'.$ids['board'].'/topics/'.$ids['topic'] => '/boards/{board}/topics/{topic}',
            '/projects/parity-core/boards/'.$ids['board'] => '/projects/parity-core/boards/{board}',
            '/news/'.$ids['news'] => '/news/{news}',
            '/documents/'.$ids['document'] => '/documents/{document}',
        ];

        return strtr($html, $replacements);
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(string $format): array
    {
        $path = Redmine701Fixture::directory().'/expectations/markup/'.$format.'.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
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

    private function seedTargets(): void
    {
        $wiki = Wiki::query()->create(['project_id' => 1, 'start_page' => 'Guide', 'status' => 1]);
        $guide = WikiPage::query()->create([
            'wiki_id' => $wiki->id,
            'title' => 'Guide',
            'protected' => false,
            'created_on' => '2026-10-01 12:00:00',
        ]);
        WikiContent::query()->create([
            'page_id' => $guide->id,
            'author_id' => 1,
            'text' => "Guide body\n",
            'version' => 1,
            'updated_on' => '2026-10-06 12:00:00',
        ]);
        $child = WikiPage::query()->create([
            'wiki_id' => $wiki->id,
            'title' => 'Child',
            'parent_id' => $guide->id,
            'protected' => false,
            'created_on' => '2026-10-02 12:00:00',
        ]);
        WikiContent::query()->create([
            'page_id' => $child->id,
            'author_id' => 1,
            'text' => "Child body\n",
            'version' => 1,
            'updated_on' => '2026-10-05 12:00:00',
        ]);
        $included = WikiPage::query()->create([
            'wiki_id' => $wiki->id,
            'title' => 'Included',
            'protected' => false,
            'created_on' => '2026-10-03 12:00:00',
        ]);
        WikiContent::query()->create([
            'page_id' => $included->id,
            'author_id' => 1,
            'text' => "Hello from include\n",
            'version' => 1,
            'updated_on' => '2026-10-04 12:00:00',
        ]);
        $old = WikiPage::query()->create([
            'wiki_id' => $wiki->id,
            'title' => 'Old',
            'protected' => false,
            'created_on' => '2026-09-01 12:00:00',
        ]);
        WikiContent::query()->create([
            'page_id' => $old->id,
            'author_id' => 1,
            'text' => "Old body\n",
            'version' => 1,
            'updated_on' => '2026-09-01 12:00:00',
        ]);
        News::query()->create([
            'project_id' => 1,
            'author_id' => 1,
            'title' => 'Greetings',
            'summary' => 'Hi',
            'description' => 'News body',
            'comments_count' => 0,
            'created_on' => '2026-10-01 12:00:00',
        ]);
        Document::query()->create([
            'project_id' => 1,
            'category_id' => 4,
            'title' => 'Spec',
            'description' => 'Document body',
            'created_on' => '2026-10-01 12:00:00',
        ]);
        $board = Board::query()->create([
            'project_id' => 1,
            'name' => 'Discussion',
            'description' => '',
            'position' => 1,
            'topics_count' => 1,
            'messages_count' => 2,
        ]);
        $topic = Message::query()->create([
            'board_id' => $board->id,
            'author_id' => 1,
            'subject' => 'Hello',
            'content' => 'Topic body',
            'replies_count' => 1,
            'locked' => false,
            'sticky' => 0,
            'created_on' => '2026-10-01 12:00:00',
            'updated_on' => '2026-10-01 12:00:00',
        ]);
        Message::query()->create([
            'board_id' => $board->id,
            'parent_id' => $topic->id,
            'author_id' => 1,
            'subject' => 'Reply',
            'content' => 'Reply body',
            'replies_count' => 0,
            'locked' => false,
            'sticky' => 0,
            'created_on' => '2026-10-02 12:00:00',
            'updated_on' => '2026-10-02 12:00:00',
        ]);
        Attachment::query()->create([
            'author_id' => 1,
            'container_id' => 1,
            'container_type' => 'Issue',
            'content_type' => 'image/png',
            'created_on' => '2026-10-01 12:00:00',
            'disk_filename' => 'picture.png',
            'downloads' => 0,
            'filename' => 'picture.png',
            'filesize' => 8,
        ]);
    }
}
