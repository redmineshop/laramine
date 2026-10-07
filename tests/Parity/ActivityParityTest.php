<?php

namespace Tests\Parity;

use App\Domain\Activity\ActivityEvent;
use App\Domain\Activity\ActivityProvider;
use App\Domain\Auth\ActionToken;
use App\Domain\Settings\SettingValue;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares activity for issues, journals, and time entries, plus Atom feeds, to the pin.
 *
 * News, documents, wiki, messages, files, and changesets stay out of this comparison.
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

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Activity \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/ActivityParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/activity/events.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for news, documents, wiki, messages, files, and changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — full REST API \| NOT VERIFIED \|/m', $checklist);
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
