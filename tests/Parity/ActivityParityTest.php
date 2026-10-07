<?php

namespace Tests\Parity;

use App\Domain\Activity\ActivityEvent;
use App\Domain\Activity\ActivityProvider;
use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\Auth\ActionToken;
use App\Domain\Boards\BoardService;
use App\Domain\Boards\MessageService;
use App\Domain\Documents\DocumentService;
use App\Domain\News\NewsService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\SettingValue;
use App\Domain\Wiki\WikiService;
use App\Models\EnabledModule;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares activity for issues, journals, time entries, news, documents, files, wiki edits, and messages to the pin.
 *
 * Changesets stay out of this comparison.
 */
class ActivityParityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_activity_events_match_visibility_and_the_day_window(): void
    {
        Redmine701Fixture::load();
        $this->setting(SettingValue::HOST_NAME, 'parity.test');
        $this->setting(SettingValue::MAIL_FROM, 'laramine@parity.test');
        $expected = $this->expectation();
        $provider = app(ActivityProvider::class);
        $window = $expected['window'];
        $this->assertIsArray($window);
        $from = $this->stringField($window, 'from');
        $days = $this->intField($window, 'days');

        $actors = $expected['actors'];
        $this->assertIsArray($actors);
        foreach ($actors as $login => $rows) {
            $this->assertIsString($login);
            $this->assertIsArray($rows);
            $actual = $this->rows($provider->events($this->user($login), null, $from, $days));
            $this->assertSame($rows, $actual, $login);
        }

        $project = Project::query()->where('identifier', 'parity-core')->first();
        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame($expected['project_ada'], $this->rows($provider->events($this->user('ada'), $project, $from, $days)));

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->setting(SettingValue::ACTIVITY_DAYS_DEFAULT, '30');
        $this->assertSame([], $this->rows($provider->events($this->user('ada'), null, null, null)));
        $this->setting(SettingValue::ACTIVITY_DAYS_DEFAULT, '2');
        $this->assertSame($expected['actors']['ada'], $this->rows($provider->events($this->user('ada'), null, $from, null)));
    }

    public function test_atom_feeds_follow_the_feed_token_and_issue_visibility(): void
    {
        Redmine701Fixture::load();
        $this->setting(SettingValue::HOST_NAME, 'parity.test');
        $this->setting(SettingValue::MAIL_FROM, 'laramine@parity.test');
        $expected = $this->expectation();
        $provider = app(ActivityProvider::class);
        $issues = $expected['issues'];
        $titles = $expected['issue_titles'];
        $this->assertIsArray($issues);
        $this->assertIsArray($titles);

        $this->assertSame($issues['ada'], $this->ids($provider->issueList($this->user('ada'), null)));
        $this->assertSame($issues['admin'], $this->ids($provider->issueList($this->user('admin'), null)));
        $this->assertSame($issues['cleo'], $this->ids($provider->issueList($this->user('cleo'), null)));
        $project = Project::query()->where('identifier', 'parity-core')->first();
        $this->assertInstanceOf(Project::class, $project);
        $coreIds = $this->ids($provider->issueList($this->user('ada'), $project));
        $this->assertSame($issues['parity_core_ada'], $coreIds);
        foreach ($provider->issueList($this->user('admin'), null) as $event) {
            $this->assertSame($titles[(string) $event->id], $event->title);
        }

        $adaKey = app(ActionToken::class)->issueNamed($this->user('ada'), Token::ACTION_FEEDS)->value;
        $apiKey = app(ActionToken::class)->issueNamed($this->user('ada'), Token::ACTION_API)->value;
        $query = 'from=2026-08-27&days=2&key='.$adaKey;

        $this->get('/activity.atom')->assertUnauthorized();
        $this->get('/activity.atom?key='.$apiKey)->assertUnauthorized();
        $this->get('/issues.atom?key='.$apiKey)->assertUnauthorized();

        $activity = $this->get('/activity.atom?'.$query);
        $activity->assertOk();
        $activity->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
        $this->assertFeedEntries($activity->getContent(), $expected['actors']['ada'] ?? []);

        $projectFeed = $this->get('/projects/parity-core/activity.atom?'.$query);
        $projectFeed->assertOk();
        $this->assertFeedEntries($projectFeed->getContent(), $expected['project_ada'] ?? []);

        $page = $this->get('/activity?'.$query);
        $page->assertOk();
        $page->assertSee('Activity', false);
        $page->assertSee('1.00 hours (Development) on #1', false);
        $page->assertSee('3.00 hours (Development) on #5', false);
        $corePage = $this->get('/projects/parity-core/activity?'.$query);
        $corePage->assertOk();
        $corePage->assertSee('1.00 hours (Development) on #1', false);
        $corePage->assertDontSee('3.00 hours (Development) on #5', false);

        $globalIssues = $this->get('/issues.atom?key='.$adaKey);
        $globalIssues->assertOk();
        $this->assertIssueEntries($globalIssues->getContent(), $issues['ada'], $titles);

        $coreIssues = $this->get('/projects/parity-core/issues.atom?key='.$adaKey);
        $coreIssues->assertOk();
        $this->assertIssueEntries($coreIssues->getContent(), $issues['parity_core_ada'], $titles);

        $my = $this->get('/my.atom?key='.$adaKey);
        $my->assertOk();
        $my->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
        $my->assertSee('<name>ada</name>', false);

        $scoped = $this->get('/my.atom?'.$query);
        $scoped->assertOk();
        $scoped->assertSee('<name>ada</name>', false);
        $scoped->assertSee('tag:parity.test,2026-08-27:time_entry/5', false);
    }

    public function test_attachment_journal_uses_the_container_row_and_time_titles_use_hour_value(): void
    {
        Redmine701Fixture::load();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $ada = $this->user('ada');
        $container = app(AttachmentContainerService::class);
        $uploaded = $container->upload($ada, 'pin.txt', 'pin-bytes', 'text/plain');
        $attachment = $container->claim($ada, $uploaded['token'], 1, null, null, null);
        $events = app(ActivityProvider::class)->events($ada, null, '2026-10-07', 0);
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('journal', $event->kind);
        $this->assertSame('ada', $event->author);
        $this->assertSame('Bug #1 (New): Parity pin parent', $event->title);
        $detail = JournalDetail::query()->where('journal_id', $event->id)->first();
        $this->assertInstanceOf(JournalDetail::class, $detail);
        $this->assertSame((string) $attachment->id, (string) $detail->prop_key);

        $window = app(ActivityProvider::class)->events($ada, null, '2026-08-27', 2);
        $time = null;
        foreach ($window as $row) {
            if ($row->kind === 'time_entry' && $row->id === 1) {
                $time = $row;
            }
        }
        $this->assertInstanceOf(ActivityEvent::class, $time);
        $this->assertSame('1.50 hours (Development) on #1', $time->title);
    }

    public function test_news_documents_and_files_match_the_module_window(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $modules = $expected['modules'];
        $this->assertIsArray($modules);
        Carbon::setTestNow($this->stringField($modules, 'now'));
        $project = Project::query()->where('identifier', 'parity-core')->first();
        $child = Project::query()->where('identifier', 'parity-child')->first();
        $this->assertInstanceOf(Project::class, $project);
        $this->assertInstanceOf(Project::class, $child);
        $projects = app(ProjectService::class);
        $projects->enableModule($project, 'news');
        $projects->enableModule($project, 'documents');
        $projects->enableModule($project, 'files');
        $this->grant(1, ['view_news', 'view_files']);
        $this->grant(2, ['view_news', 'view_documents', 'view_files', 'add_documents', 'edit_documents', 'manage_files']);

        $admin = $this->user('admin');
        $bea = $this->user('bea');
        $news = app(NewsService::class)->create($admin, $project, 'Release', null, null);
        $bare = app(DocumentService::class)->create($admin, $project, 'Bare', 4, null);
        $guide = app(DocumentService::class)->create($admin, $project, 'Guide', 4, null);
        app(DocumentService::class)->create($admin, $child, 'Hidden', 4, null);

        $container = app(AttachmentContainerService::class);
        $guideUpload = $container->upload($bea, 'guide-a.txt', 'guide', 'text/plain');
        $container->claim($bea, $guideUpload['token'], null, null, 'guide-a.txt', null, (int) $guide->id);
        $projectUpload = $container->upload($admin, 'tree.txt', 'tree', 'text/plain');
        $projectFile = $container->claim($admin, $projectUpload['token'], null, null, 'tree.txt', null, null, (int) $project->id);
        $versionUpload = $container->upload($bea, 'build.txt', 'build', 'text/plain');
        $versionFile = $container->claim($bea, $versionUpload['token'], null, null, 'build.txt', null, null, (int) $project->id, 1);
        $ids = [
            'news' => (int) $news->id,
            'bare' => (int) $bare->id,
            'guide' => (int) $guide->id,
            'project_file' => (int) $projectFile->id,
            'version_file' => (int) $versionFile->id,
        ];

        $provider = app(ActivityProvider::class);
        $from = $this->stringField($modules, 'from');
        $days = $this->intField($modules, 'days');
        foreach (['ada', 'admin', 'bea', 'cleo'] as $login) {
            $rows = $modules[$login];
            $this->assertIsArray($rows);
            $this->assertSame($this->withIds($rows, $ids), $this->rows($provider->events($this->user($login), null, $from, $days)), $login);
        }
    }

    public function test_wiki_and_message_events_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->setting(SettingValue::HOST_NAME, 'parity.test');
        $this->setting(SettingValue::MAIL_FROM, 'laramine@parity.test');
        $this->enableModule('wiki');
        $this->enableModule('boards');
        $this->grant(1, ['view_wiki_pages', 'view_wiki_edits', 'edit_wiki_pages', 'view_messages', 'add_messages']);
        $this->grant(2, ['view_messages', 'add_messages', 'manage_boards']);
        $this->grant(7, ['view_messages']);
        $expected = $this->moduleExpectation();
        $project = Project::query()->where('identifier', 'parity-core')->first();
        $this->assertInstanceOf(Project::class, $project);
        $ada = $this->user('ada');
        $bea = $this->user('bea');
        $wiki = app(WikiService::class);
        $messages = app(MessageService::class);

        Carbon::setTestNow('2026-10-07 12:00:00');
        $page = $wiki->createPage($ada, $project, 'cook_book', "alpha\n", 'first', null, false, false);
        $topic = $messages->postTopic($ada, app(BoardService::class)->create($bea, $project, 'General', null, null, null), 'Hello', 'hello', 0, false, false);
        Carbon::setTestNow('2026-10-07 12:30:00');
        $messages->reply($bea, $topic, null, 'answer', false);
        Carbon::setTestNow('2026-10-07 13:00:00');
        $wiki->updateContent($ada, $page, "beta\n", 'second', false);
        Carbon::setTestNow('2026-10-07 14:00:00');
        $wiki->updateContent($ada, $page, "gamma\n", 'third', false);
        $messages->postTopic($ada, $topic->board()->firstOrFail(), 'Same', 'same', 0, false, false);

        $provider = app(ActivityProvider::class);
        $this->assertSame($expected['ada'], $this->bare($provider->events($ada, null, '2026-10-07', 0)));
        $this->assertSame($expected['ada'], $this->bare($provider->events($this->user('admin'), null, '2026-10-07', 0)));
        $this->assertSame($expected['finn'], $this->bare($provider->events($this->user('finn'), null, '2026-10-07', 0)));
        $this->assertSame($expected['cleo'], $this->bare($provider->events($this->user('cleo'), null, '2026-10-07', 0)));

        EnabledModule::query()->where('project_id', $project->id)->whereIn('name', ['wiki', 'boards'])->delete();
        $this->assertSame($expected['modules_off'], $this->bare($provider->events($this->user('admin'), null, '2026-10-07', 0)));

        $this->enableModule('wiki');
        $this->enableModule('boards');
        $key = app(ActionToken::class)->issueNamed($ada, Token::ACTION_FEEDS)->value;
        $feed = $this->get('/activity.atom?from=2026-10-07&days=0&key='.$key);
        $feed->assertOk();
        $feed->assertSee('Wiki edit: Cook book (#3)', false);
        $feed->assertSee('General: Hello', false);
        $pageResponse = $this->get('/activity?from=2026-10-07&days=0&key='.$key);
        $pageResponse->assertOk();
        $pageResponse->assertSee('Wiki edit: Cook book (#3)', false);
        $pageResponse->assertSee('General: Same', false);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Activity \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/ActivityParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/activity/events.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for news, documents, and files \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for wiki and messages \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/activity/modules.json', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — full REST API \| NOT VERIFIED \|/m', $checklist);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $ids
     * @return list<array<string, mixed>>
     */
    private function withIds(array $rows, array $ids): array
    {
        $resolved = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_string($id)) {
                $this->assertArrayHasKey($id, $ids);
                $row['id'] = $ids[$id];
            }
            $resolved[] = $row;
        }

        return $resolved;
    }

    /**
     * @param  list<ActivityEvent>  $events
     * @return list<array{kind: string, id: int, project: string, author: string, title: string, at: string}>
     */
    private function rows(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = $event->toArray();
        }

        return $rows;
    }

    /**
     * @param  list<ActivityEvent>  $events
     * @return list<int>
     */
    private function ids(array $events): array
    {
        $ids = [];
        foreach ($events as $event) {
            $ids[] = $event->id;
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $expected
     */
    private function assertFeedEntries(string|false $body, array $expected): void
    {
        $this->assertIsString($body);
        $this->assertStringContainsString('<name>ada</name>', $body);
        foreach ($expected as $row) {
            $this->assertIsArray($row);
            $kind = $row['kind'] ?? null;
            $id = $row['id'] ?? null;
            $at = $row['at'] ?? null;
            $title = $row['title'] ?? null;
            $this->assertIsString($kind);
            $this->assertIsInt($id);
            $this->assertIsString($at);
            $this->assertIsString($title);
            $this->assertStringContainsString('tag:parity.test,'.substr($at, 0, 10).':'.$kind.'/'.$id, $body);
            $this->assertStringContainsString($title, $body);
        }
    }

    /**
     * @param  list<int>|mixed  $ids
     * @param  array<mixed>  $titles
     */
    private function assertIssueEntries(string|false $body, mixed $ids, array $titles): void
    {
        $this->assertIsString($body);
        $this->assertIsArray($ids);
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $title = $titles[(string) $id] ?? null;
            $this->assertIsString($title);
            $this->assertStringContainsString('tag:parity.test,', $body);
            $this->assertStringContainsString(':issue/'.$id, $body);
            $this->assertStringContainsString($title, $body);
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function moduleExpectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/activity/modules.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  list<ActivityEvent>  $events
     * @return list<array{kind: string, project: string, author: string, title: string, at: string}>
     */
    private function bare(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'kind' => $event->kind,
                'project' => $event->project,
                'author' => $event->author,
                'title' => $event->title,
                'at' => $event->at,
            ];
        }

        return $rows;
    }

    private function enableModule(string $name): void
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

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/activity/events.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->assertIsInt($value);

        return $value;
    }
}
