<?php

namespace Tests\Parity;

use App\Domain\Settings\SettingValue;
use App\Models\Board;
use App\Models\EnabledModule;
use App\Models\Message;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use JsonException;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares the boards and messages HTTP surface to the pin.
 *
 * The service row stays on BoardsParityTest. These pages are not a Redmine screen.
 */
class BoardsHttpParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_http_routes_acl_topics_and_rendered_content_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->enable('boards');
        $this->textile();
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

        $ada = $this->user('ada');
        $bea = $this->user('bea');
        $finn = $this->user('finn');
        $admin = $this->user('admin');
        $project = $this->project();

        $guest = $this->get('/projects/parity-core/boards');
        $guestJson = $this->getJson('/projects/parity-core/boards');
        $adaNew = $this->actingAs($ada)->get('/projects/parity-core/boards/new');
        $finnPost = $this->actingAs($finn)->post('/projects/parity-core/boards', ['name' => 'Nope']);

        EnabledModule::query()->where('project_id', $project->id)->where('name', 'boards')->delete();
        $moduleOff = $this->actingAs($admin)->get('/projects/parity-core/boards');
        $this->enable('boards');

        $empty = $this->actingAs($bea)->postJson('/projects/parity-core/boards', ['name' => '  ']);
        $created = $this->actingAs($bea)->post('/projects/parity-core/boards', [
            'name' => 'Discussion',
            'description' => 'Talk',
        ]);
        $board = Board::query()->where('name', 'Discussion')->first();
        $this->assertInstanceOf(Board::class, $board);
        $child = $this->actingAs($bea)->post('/projects/parity-core/boards', [
            'name' => 'Help',
            'parent_id' => (int) $board->id,
        ]);
        $help = Board::query()->where('name', 'Help')->first();
        $this->assertInstanceOf(Board::class, $help);
        $index = $this->actingAs($ada)->get('/projects/parity-core/boards');
        $edited = $this->actingAs($bea)->put('/projects/parity-core/boards/'.$board->id, [
            'name' => 'General',
            'description' => 'Talk',
        ]);
        $cycle = $this->actingAs($bea)->putJson('/projects/parity-core/boards/'.$board->id, [
            'name' => 'General',
            'description' => 'Talk',
            'parent_id' => (int) $help->id,
        ]);
        $show = $this->actingAs($ada)->get('/projects/parity-core/boards/'.$board->id);

        $apple = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics', [
            'subject' => 'Apple',
            'content' => '*word*',
        ]);
        $zebra = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics', [
            'subject' => 'Zebra',
            'content' => 'tail',
        ]);
        $stickyDenied = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics', [
            'subject' => 'Secret sticky',
            'content' => 'no',
            'sticky' => '1',
        ]);
        $mango = $this->actingAs($bea)->post('/boards/'.$board->id.'/topics', [
            'subject' => 'Mango',
            'content' => 'ripe',
            'sticky' => '1',
            'locked' => '1',
        ]);
        $appleRow = Message::query()->where('subject', 'Apple')->first();
        $mangoRow = Message::query()->where('subject', 'Mango')->first();
        $this->assertInstanceOf(Message::class, $appleRow);
        $this->assertInstanceOf(Message::class, $mangoRow);

        $sorted = $this->actingAs($ada)->get('/projects/parity-core/boards/'.$board->id.'?sort=subject:asc&per_page=2&page=1');
        $pageTwo = $this->actingAs($ada)->get('/projects/parity-core/boards/'.$board->id.'?sort=subject:asc&per_page=2&page=2');
        $defaultOrder = $this->actingAs($ada)->get('/projects/parity-core/boards/'.$board->id);
        $topic = $this->actingAs($ada)->get('/boards/'.$board->id.'/topics/'.$appleRow->id);
        $topicBlock = $this->props($topic)['topic'] ?? null;
        $this->assertIsArray($topicBlock);

        $reply = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics/'.$appleRow->id.'/replies', [
            'content' => 'answer',
        ]);
        $lockedReply = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics/'.$mangoRow->id.'/replies', [
            'content' => 'no',
        ]);
        $moderatorReply = $this->actingAs($bea)->post('/boards/'.$board->id.'/topics/'.$mangoRow->id.'/replies', [
            'content' => 'yes',
        ]);
        $shown = $this->actingAs($ada)->get('/boards/'.$board->id.'/topics/'.$appleRow->id);
        $replyRow = Message::query()->where('parent_id', $appleRow->id)->first();
        $this->assertInstanceOf(Message::class, $replyRow);

        $ownEdit = $this->actingAs($ada)->put('/boards/'.$board->id.'/topics/'.$appleRow->id, [
            'subject' => 'Apple pie',
            'content' => '*word*',
        ]);
        $othersEdit = $this->actingAs($ada)->put('/boards/'.$board->id.'/topics/'.$mangoRow->id, [
            'subject' => 'Taken',
            'content' => 'no',
        ]);
        $moderatorEdit = $this->actingAs($bea)->put('/boards/'.$board->id.'/topics/'.$appleRow->id, [
            'subject' => 'Apple',
            'content' => '*word*',
        ]);
        $quote = $this->actingAs($bea)->get('/boards/'.$board->id.'/topics/'.$appleRow->id.'/quote');
        $preview = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics/preview', [
            'content' => '*word*',
        ]);
        $previewDenied = $this->actingAs($finn)->post('/boards/'.$board->id.'/topics/preview', [
            'content' => '*word*',
        ]);

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
        $attached = $this->actingAs($ada)->post('/boards/'.$board->id.'/topics/'.$appleRow->id.'/attachments', [
            'token' => $token,
            'filename' => 'note.txt',
        ]);
        $withFile = $this->actingAs($ada)->get('/boards/'.$board->id.'/topics/'.$appleRow->id);

        $watched = $this->actingAs($ada)->from('/boards/'.$board->id.'/topics/'.$appleRow->id)->post('/watchers/watch', [
            'object_type' => 'message',
            'object_id' => (int) $appleRow->id,
        ]);
        $watchers = $this->actingAs($ada)->get('/boards/'.$board->id.'/topics/'.$appleRow->id);
        $hidden = $this->actingAs($finn)->get('/boards/'.$board->id.'/topics/'.$appleRow->id);

        $ownDelete = $this->actingAs($ada)->delete('/boards/'.$board->id.'/topics/'.$replyRow->id);
        $otherDelete = $this->actingAs($ada)->delete('/boards/'.$board->id.'/topics/'.$mangoRow->id);
        $moderatorDelete = $this->actingAs($bea)->delete('/boards/'.$board->id.'/topics/'.$mangoRow->id);

        $project->status = Project::STATUS_CLOSED;
        $project->save();
        $closedRead = $this->actingAs($ada)->get('/projects/parity-core/boards/'.$board->id);
        $closedWrite = $this->actingAs($admin)->post('/boards/'.$board->id.'/topics', [
            'subject' => 'Closed',
            'content' => 'no',
        ]);
        $project->status = Project::STATUS_ARCHIVED;
        $project->save();
        $archived = $this->actingAs($admin)->get('/projects/parity-core/boards/'.$board->id);
        $project->status = Project::STATUS_ACTIVE;
        $project->save();

        $removed = $this->actingAs($bea)->delete('/projects/parity-core/boards/'.$help->id);
        EnabledModule::query()->where('project_id', $project->id)->where('name', 'boards')->delete();
        $disabled = $this->actingAs($admin)->get('/projects/parity-core/boards');

        $actual = [
            'routes' => $this->routes(),
            'guest_html' => $guest->status(),
            'guest_location' => $this->location($guest),
            'guest_json' => $guestJson->status(),
            'ada_new_board' => $adaNew->status(),
            'finn_post_board' => $finnPost->status(),
            'module_off_admin' => $moduleOff->status(),
            'empty_name' => $empty->status(),
            'empty_name_message' => $empty->json('message'),
            'created' => $created->status(),
            'created_location_matches_board' => $this->location($created) === '/projects/parity-core/boards/'.$board->id,
            'child' => $child->status(),
            'index_component' => $this->pageComponent($index),
            'index_names' => array_column($this->rows($this->props($index), 'boards'), 'name'),
            'index_depths' => array_column($this->rows($this->props($index), 'boards'), 'depth'),
            'edited' => $edited->status(),
            'cycle' => $cycle->status(),
            'cycle_message' => $cycle->json('message'),
            'show_component' => $this->pageComponent($show),
            'apple' => $apple->status(),
            'zebra' => $zebra->status(),
            'sticky_denied' => $stickyDenied->status(),
            'secret_sticky_exists' => Message::query()->where('subject', 'Secret sticky')->exists(),
            'mango' => $mango->status(),
            'sorted_subjects' => array_column($this->rows($this->props($sorted), 'topics'), 'subject'),
            'sorted_page' => $this->props($sorted)['page'] ?? null,
            'sorted_pages' => $this->props($sorted)['pages'] ?? null,
            'page_two_subjects' => array_column($this->rows($this->props($pageTwo), 'topics'), 'subject'),
            'default_subjects' => array_column($this->rows($this->props($defaultOrder), 'topics'), 'subject'),
            'topic_component' => $this->pageComponent($topic),
            'topic_html' => $topicBlock['content_html'] ?? null,
            'reply' => $reply->status(),
            'locked_reply' => $lockedReply->status(),
            'moderator_reply' => $moderatorReply->status(),
            'reply_subjects' => array_column($this->rows($this->props($shown), 'replies'), 'subject'),
            'reply_html_has_answer' => str_contains((string) json_encode($this->props($shown)['replies'] ?? null), 'answer'),
            'own_edit' => $ownEdit->status(),
            'others_edit' => $othersEdit->status(),
            'moderator_edit' => $moderatorEdit->status(),
            'quote_component' => $this->pageComponent($quote),
            'quote_content' => $this->props($quote)['content'] ?? null,
            'preview' => $preview->getContent(),
            'preview_denied' => $previewDenied->status(),
            'attached' => $attached->status(),
            'attachment_names' => array_column($this->rows($this->props($withFile), 'attachments'), 'filename'),
            'watched' => $watched->status(),
            'watcher_ids' => $this->props($watchers)['watcherIds'] ?? null,
            'watching' => $this->props($watchers)['watching'] ?? null,
            'finn_watcher_ids' => $this->props($hidden)['watcherIds'] ?? null,
            'own_delete' => $ownDelete->status(),
            'other_delete' => $otherDelete->status(),
            'moderator_delete' => $moderatorDelete->status(),
            'mango_exists' => Message::query()->whereKey($mangoRow->id)->exists(),
            'closed_read' => $closedRead->status(),
            'closed_write' => $closedWrite->status(),
            'archived_admin' => $archived->status(),
            'removed_child' => $removed->status(),
            'help_exists' => Board::query()->whereKey($help->id)->exists(),
            'disabled_admin' => $disabled->status(),
        ];

        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/boards/http.json', $actual);
    }

    public function test_checklist_keeps_verified_rows_and_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki and boards visual UX \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Queries \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Textile and Markdown rendering \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Calendar and Gantt \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/BoardsHttpParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/boards/http.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/remainder.json', $checklist);
    }

    /**
     * @return list<string>
     */
    private function routes(): array
    {
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_contains('/'.$uri, '/boards') && ! str_starts_with($uri, 'watchers')) {
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

    private function textile(): void
    {
        Setting::query()->create([
            'name' => SettingValue::TEXT_FORMATTING,
            'value' => 'textile',
            'updated_on' => '2026-10-07 12:00:00',
        ]);
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
     * @return list<array<string, mixed>>
     */
    private function rows(array $props, string $key): array
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

    private function location(TestResponse $response): string
    {
        $header = $response->headers->get('Location');
        $this->assertIsString($header, 'status '.$response->status().' body '.substr($response->getContent(), 0, 500));
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
