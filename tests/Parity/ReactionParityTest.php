<?php

namespace Tests\Parity;

use App\Domain\Boards\BoardService;
use App\Domain\Boards\MessageService;
use App\Domain\Issues\IssueDeletion;
use App\Domain\News\NewsService;
use App\Domain\Reactions\ReactionButton;
use App\Domain\Reactions\ReactionService;
use App\Domain\Settings\SettingValue;
use App\Models\EnabledModule;
use App\Models\Issue;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Reaction;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares reactions to the pin: the setting, record visibility, active
 * project, the visible-user count and tooltip, add and remove over HTTP, and
 * the rows removed with their records.
 *
 * The expectation file is written by hand from the 7.0.1 semantics. It is not
 * recorded from this tree.
 */
class ReactionParityTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTATION = 'tests/Parity/fixtures/redmine-7.0.1/expectations/reactions/http.json';

    public function test_reactions_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->enable('news');
        $this->enable('boards');
        $this->grant(1, ['view_news', 'comment_news', 'view_messages', 'add_messages']);
        $this->grant(2, ['view_news', 'manage_news', 'comment_news', 'view_messages', 'add_messages', 'edit_messages', 'delete_messages', 'manage_boards']);
        $this->grant(4, ['view_news']);
        $this->grant(5, ['view_news']);
        $this->grant(6, ['view_news']);
        $this->grant(7, ['view_news', 'view_messages']);

        $ada = $this->user('ada');
        $bea = $this->user('bea');
        $admin = $this->user('admin');
        $cleo = $this->user('cleo');
        $erin = $this->user('erin');
        $finn = $this->user('finn');
        $reactions = app(ReactionService::class);
        $actual = ['routes' => $this->routes()];

        $guest = $this->post('/reactions', ['object_type' => 'Issue', 'object_id' => 1]);
        $actual['guest_html'] = $guest->status();
        $actual['guest_location'] = (string) parse_url((string) $guest->headers->get('Location'), PHP_URL_PATH);
        $actual['guest_json'] = $this->postJson('/reactions', ['object_type' => 'Issue', 'object_id' => 1])->status();

        $actual['html_request'] = $this->actingAs($ada)->post('/reactions', ['object_type' => 'Issue', 'object_id' => 1])->status();
        $actual['html_rows'] = $this->rows('Issue', 1);

        Setting::query()->create(['name' => SettingValue::REACTIONS_ENABLED, 'value' => '0']);
        $actual['disabled_post'] = $this->react($ada, 'Issue', 1)->status();
        $actual['disabled_button'] = $reactions->button($ada, $reactions->target('Issue', 1))?->toArray();
        Setting::query()->where('name', SettingValue::REACTIONS_ENABLED)->delete();

        $actual['unknown_type'] = $this->react($ada, 'Project', 1)->status();
        $actual['lowercase_type'] = $this->react($ada, 'issue', 1)->status();
        $actual['missing_issue'] = $this->react($ada, 'Issue', 999)->status();
        $actual['missing_journal'] = $this->react($ada, 'Journal', 999)->status();

        $created = $this->react($ada, 'Issue', 1);
        $actual['created'] = $created->status();
        $actual['created_body'] = $this->body($created);
        $actual['repeat'] = $this->react($ada, 'Issue', 1)->status();
        $actual['repeat_rows'] = $this->rows('Issue', 1);

        $actual['cleo_issue'] = $this->react($cleo, 'Issue', 1)->status();
        $actual['ada_private_issue'] = $this->react($ada, 'Issue', 4)->status();
        $actual['bea_private_issue'] = $this->react($bea, 'Issue', 4)->status();
        $actual['ada_private_journal'] = $this->react($ada, 'Journal', 2)->status();
        $actual['ada_own_private_journal'] = $this->react($ada, 'Journal', 7)->status();

        foreach ([$ada, $bea, $admin, $erin, $finn] as $person) {
            $this->assertSame(200, $this->react($person, 'Journal', 8)->status());
        }
        // Lars is locked and Dora cannot see the issue. Their rows are stored
        // state, as an older reaction would be.
        foreach (['lars', 'dora'] as $login) {
            Reaction::query()->create([
                'reactable_type' => 'Journal',
                'reactable_id' => 8,
                'user_id' => (int) $this->user($login)->id,
            ]);
        }
        $journal = $reactions->target('Journal', 8);
        $actual['journal_buttons'] = [
            'ada' => $this->button($reactions->button($ada, $journal)),
            'finn' => $this->button($reactions->button($finn, $journal)),
            'erin' => $this->button($reactions->button($erin, $journal)),
            'admin' => $this->button($reactions->button($admin, $journal)),
            'cleo' => $this->button($reactions->button($cleo, $journal)),
            'guest' => $this->button($reactions->button(null, $journal)),
        ];

        $beaRow = $this->reaction('Journal', 8, $bea);
        $actual['foreign_delete'] = $this->unreact($ada, 'Journal', 8, (int) $beaRow->id)->status();
        $actual['foreign_rows'] = $this->rows('Journal', 8);
        $adaIssueRow = $this->reaction('Issue', 1, $ada);
        $actual['mismatched_delete'] = $this->unreact($ada, 'Journal', 8, (int) $adaIssueRow->id)->status();
        $actual['mismatched_issue_rows'] = $this->rows('Issue', 1);
        $adaRow = $this->reaction('Journal', 8, $ada);
        $ownDelete = $this->unreact($ada, 'Journal', 8, (int) $adaRow->id);
        $actual['own_delete'] = $ownDelete->status();
        $actual['own_delete_body'] = $this->body($ownDelete);

        $project = $this->project();
        $newsService = app(NewsService::class);
        $news = $newsService->create($bea, $project, 'Release', 'Short', 'Body');
        $first = $newsService->addComment($ada, $news, 'First');
        $second = $newsService->addComment($bea, $news, 'Second');
        $newsId = (int) $news->id;
        $actual['news_ada'] = $this->react($ada, 'News', $newsId)->status();
        $actual['news_cleo'] = $this->react($cleo, 'News', $newsId)->status();
        $actual['news_guest'] = $this->news($reactions->button(null, $reactions->target('News', $newsId)), $newsId);
        $actual['comment_bea'] = $this->react($bea, 'Comment', (int) $first->id)->status();

        $extra = $this->extraUsers(11);
        foreach ($extra as $person) {
            $this->assertSame(200, $this->react($person, 'News', $newsId)->status());
        }
        $newsTarget = $reactions->target('News', $newsId);
        $actual['many_bea'] = $this->news($reactions->button($bea, $newsTarget), $newsId);
        $adaNews = $this->reaction('News', $newsId, $ada);
        $this->assertSame(200, $this->unreact($ada, 'News', $newsId, (int) $adaNews->id)->status());
        $actual['many_after_unreact'] = $this->news($reactions->button($extra[0], $newsTarget), $newsId);

        $boards = app(BoardService::class);
        $messages = app(MessageService::class);
        $board = $boards->create($bea, $project, 'General', null, null, null);
        $topic = $messages->postTopic($ada, $board, 'Hello', 'Body', 0, false, false);
        $reply = $messages->reply($bea, $topic, null, 'Answer', false);
        $actual['topic_ada'] = $this->react($ada, 'Message', (int) $topic->id)->status();
        $actual['reply_finn'] = $this->react($finn, 'Message', (int) $reply->id)->status();
        $actual['topic_cleo'] = $this->react($cleo, 'Message', (int) $topic->id)->status();

        $this->projectStatus(Project::STATUS_CLOSED);
        $actual['closed_ada'] = $this->react($ada, 'Comment', (int) $second->id)->status();
        $actual['closed_admin'] = $this->react($admin, 'Comment', (int) $second->id)->status();
        $actual['closed_button'] = $this->button($reactions->button($ada, $reactions->target('Journal', 8)));
        $this->projectStatus(Project::STATUS_ARCHIVED);
        $actual['archived_ada'] = $this->react($ada, 'Journal', 8)->status();
        $actual['archived_admin_button'] = $this->button($reactions->button($admin, $reactions->target('Journal', 8)));
        $this->projectStatus(Project::STATUS_ACTIVE);

        $newsService->deleteComment($bea, $news, $first);
        $cascade = ['comment_deleted' => $this->rows('Comment', (int) $first->id)];
        $third = $newsService->addComment($ada, $news, 'Third');
        $this->assertSame(200, $this->react($ada, 'Comment', (int) $third->id)->status());
        $newsService->delete($bea, $news->refresh());
        $cascade['news_deleted'] = $this->rows('News', $newsId);
        $cascade['news_comment_left'] = $this->rows('Comment', (int) $third->id);
        $messages->delete($bea, $reply);
        $cascade['reply_deleted'] = $this->rows('Message', (int) $reply->id);
        $cascade['topic_kept'] = $this->rows('Message', (int) $topic->id);
        $boards->delete($bea, $board->refresh());
        $cascade['board_deleted'] = $this->rows('Message', (int) $topic->id);
        $this->assertSame(200, $this->react($ada, 'Issue', 2)->status());
        $this->assertSame(200, $this->react($ada, 'Journal', 3)->status());
        $issue = Issue::query()->findOrFail(2);
        app(IssueDeletion::class)->delete($bea, $issue);
        $cascade['issue_deleted'] = $this->rows('Issue', 2);
        $cascade['issue_journal_deleted'] = $this->rows('Journal', 3);
        $actual['cascade'] = $cascade;

        $this->assertPinned($actual);
    }

    public function test_tooltip_sentence_and_dom_id_follow_the_pin_helpers(): void
    {
        $this->assertSame('', ReactionService::sentence([]));
        $this->assertSame('Ada', ReactionService::sentence(['Ada']));
        $this->assertSame('Ada and Bea', ReactionService::sentence(['Ada', 'Bea']));
        $this->assertSame('Ada, Bea, and Cleo', ReactionService::sentence(['Ada', 'Bea', 'Cleo']));
        $this->assertSame('reaction_journal_8', ReactionButton::domId('Journal', 8));
        $this->assertSame('reaction_wiki_page_3', ReactionButton::domId('WikiPage', 3));
    }

    public function test_checklist_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Reactions \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/ReactionParityTest.php', $checklist);
        $this->assertStringContainsString(self::EXPECTATION, $checklist);
    }

    /**
     * @return list<string>
     */
    private function routes(): array
    {
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'reactions')) {
                continue;
            }
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $rows[] = implode('|', $methods).' '.$uri;
        }
        sort($rows);

        return $rows;
    }

    /**
     * @return TestResponse<Response>
     */
    private function react(User $user, string $type, int $id): TestResponse
    {
        return $this->actingAs($user)->postJson('/reactions', ['object_type' => $type, 'object_id' => $id]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function unreact(User $user, string $type, int $id, int $reactionId): TestResponse
    {
        return $this->actingAs($user)->deleteJson('/reactions/'.$reactionId, ['object_type' => $type, 'object_id' => $id]);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>|null
     */
    private function body(TestResponse $response): ?array
    {
        $body = $response->json('reaction');
        if (! is_array($body)) {
            return null;
        }
        $body['reaction_id'] = $body['reaction_id'] === null ? null : true;

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function button(?ReactionButton $button): ?array
    {
        if ($button === null) {
            return null;
        }
        $row = $button->toArray();
        $row['reaction_id'] = $row['reaction_id'] === null ? null : true;

        return $row;
    }

    /**
     * News ids are created by the test, so they are written as 0.
     *
     * @return array<string, mixed>|null
     */
    private function news(?ReactionButton $button, int $newsId): ?array
    {
        $row = $this->button($button);
        if ($row === null) {
            return null;
        }
        $this->assertSame($newsId, $row['object_id']);
        $row['object_id'] = 0;
        $row['dom_id'] = ReactionButton::domId('News', 0);

        return $row;
    }

    private function rows(string $type, int $id): int
    {
        return Reaction::query()->where('reactable_type', $type)->where('reactable_id', $id)->count();
    }

    private function reaction(string $type, int $id, User $user): Reaction
    {
        $row = Reaction::query()
            ->where('reactable_type', $type)
            ->where('reactable_id', $id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertInstanceOf(Reaction::class, $row);

        return $row;
    }

    /**
     * Active users `User 01` … `User N`, each an Observer on parity-core.
     *
     * @return list<User>
     */
    private function extraUsers(int $count): array
    {
        $users = [];
        for ($n = 1; $n <= $count; $n++) {
            $suffix = sprintf('%02d', $n);
            $user = User::query()->create([
                'login' => 'reactor'.$suffix,
                'firstname' => 'User',
                'lastname' => $suffix,
                'hashed_password' => '',
                'admin' => false,
                'status' => User::STATUS_ACTIVE,
                'type' => User::TYPE_USER,
                'language' => 'en',
                'mail_notification' => 'none',
            ]);
            $member = Member::query()->create([
                'user_id' => (int) $user->id,
                'project_id' => 1,
                'created_on' => now(),
                'mail_notification' => false,
            ]);
            MemberRole::query()->create(['member_id' => (int) $member->id, 'role_id' => 5]);
            $users[] = $user;
        }

        return $users;
    }

    private function projectStatus(int $status): void
    {
        $project = $this->project();
        $project->status = $status;
        $project->save();
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

    /**
     * @param  array<string, mixed>  $actual
     */
    private function assertPinned(array $actual): void
    {
        $stored = file_get_contents(base_path(self::EXPECTATION));
        $this->assertIsString($stored);
        try {
            $expected = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail($exception->getMessage());
        }
        $this->assertIsArray($expected);
        unset($expected['_source']);

        $this->assertSame($expected, $actual);
    }
}
