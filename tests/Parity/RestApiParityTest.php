<?php

namespace Tests\Parity;

use App\Domain\Auth\ActionToken;
use App\Domain\Settings\SettingValue;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use JsonException;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares representative REST JSON and XML documents to the 7.0.1 pin.
 */
class RestApiParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_resources_and_errors_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->setting(SettingValue::REST_API_ENABLED, '1');
        $admin = $this->user('admin');
        $ada = $this->user('ada');
        $adminKey = app(ActionToken::class)->issueNamed($admin, Token::ACTION_API)->value;
        $adaKey = app(ActionToken::class)->issueNamed($ada, Token::ACTION_API)->value;

        $resources = [];
        foreach ($this->paths() as $name => $path) {
            $resources[$name] = $this->document($adminKey, $path);
        }
        $resources['issue_include_ada'] = $this->document($adaKey, '/issues/1.json?include=journals');
        $resources['issue_xml'] = $this->body($adminKey, '/issues/1.xml');
        $resources['issues_xml'] = $this->body($adminKey, '/issues.xml?project_id=parity-core&limit=1');

        $this->assertPrivateNoteVisibility($resources);
        $this->assertSame(1, $resources['issues_page']['body']['limit'] ?? null);
        $this->assertArrayNotHasKey('total_count', $resources['issues_nometa']['body']);
        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/api/resources.json', $this->stabilize($resources));

        $errors = [
            'missing_key' => $this->exchange('GET', '/issues.json', []),
            'unknown_issue' => $this->exchange('GET', '/issues/99999.json', ['X-Redmine-API-Key' => $adminKey]),
            'unknown_issue_xml' => $this->exchange('GET', '/issues/99999.xml', ['X-Redmine-API-Key' => $adminKey]),
            'users_forbidden' => $this->exchange('GET', '/users.json', ['X-Redmine-API-Key' => $adaKey]),
            'blank_subject' => $this->exchange('POST', '/issues.json', ['X-Redmine-API-Key' => $adminKey], [
                'issue' => ['project_id' => 'parity-core', 'tracker_id' => 1, 'subject' => ''],
            ]),
            'blank_search' => $this->exchange('GET', '/search.json', ['X-Redmine-API-Key' => $adminKey]),
            'project_news_module' => $this->exchange('GET', '/projects/parity-core/news.json', ['X-Redmine-API-Key' => $adminKey]),
            'project_wiki_module' => $this->exchange('GET', '/projects/parity-core/wiki/index.json', ['X-Redmine-API-Key' => $adminKey]),
            'project_files_module' => $this->exchange('GET', '/projects/parity-core/files.json', ['X-Redmine-API-Key' => $adminKey]),
            'project_boards_module' => $this->exchange('GET', '/projects/parity-core/boards.json', ['X-Redmine-API-Key' => $adminKey]),
            'switch_denied' => $this->exchange('GET', '/my/account.json', [
                'X-Redmine-API-Key' => $adaKey,
                'X-Redmine-Switch-User' => 'admin',
            ]),
            'key_query' => $this->exchange('GET', '/my/account.json?key='.$adaKey, []),
            'basic_password' => $this->exchange('GET', '/my/account.json', [
                'Authorization' => 'Basic '.base64_encode('ada:secret'),
            ]),
            'basic_key' => $this->exchange('GET', '/my/account.json', [
                'Authorization' => 'Basic '.base64_encode($adaKey.':'),
            ]),
            'switch_user' => $this->exchange('GET', '/my/account.json', [
                'X-Redmine-API-Key' => $adminKey,
                'X-Redmine-Switch-User' => 'ada',
            ]),
        ];
        $this->setting(SettingValue::REST_API_ENABLED, '0');
        $errors['rest_disabled'] = $this->exchange('GET', '/issues.json', ['X-Redmine-API-Key' => $adminKey]);
        $this->assertSame(401, $errors['missing_key']['status']);
        $this->assertSame(['errors' => ['Unauthorized']], $errors['missing_key']['body']);
        $this->assertSame(404, $errors['unknown_issue']['status']);
        $this->assertSame(403, $errors['users_forbidden']['status']);
        $this->assertSame(403, $errors['project_news_module']['status']);
        $this->assertSame(403, $errors['project_wiki_module']['status']);
        $this->assertSame(403, $errors['project_files_module']['status']);
        $this->assertSame(403, $errors['project_boards_module']['status']);
        $this->assertSame(422, $errors['blank_subject']['status']);
        $this->assertSame(['errors' => ['Subject is required.']], $errors['blank_subject']['body']);
        $this->assertSame(412, $errors['switch_denied']['status']);
        $this->assertSame(['error' => 'User impersonation failed'], $errors['switch_denied']['body']);
        $this->assertSame('ada', $errors['switch_user']['body']['user']['login'] ?? null);
        $this->assertSame('ada', $errors['basic_password']['body']['user']['login'] ?? null);
        $this->assertSame('ada', $errors['basic_key']['body']['user']['login'] ?? null);
        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/api/errors.json', $this->stabilize($errors));
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — full REST API \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/RestApiParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/api/resources.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/api/errors.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — live LDAP \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki and boards visual UX \| NOT VERIFIED \|/m', $checklist);
    }

    /**
     * @return array<string, string>
     */
    private function paths(): array
    {
        return [
            'issues' => '/issues.json?project_id=parity-core&limit=100',
            'issues_page' => '/issues.json?project_id=parity-core&offset=0&limit=1',
            'issues_nometa' => '/issues.json?project_id=parity-core&limit=1&nometa=1',
            'issue' => '/issues/1.json',
            'issue_include' => '/issues/1.json?include=children,attachments,relations,journals,watchers,allowed_statuses',
            'projects' => '/projects.json',
            'project' => '/projects/parity-core.json?include=trackers,issue_categories,enabled_modules,time_entry_activities',
            'memberships' => '/projects/parity-core/memberships.json',
            'versions' => '/projects/parity-core/versions.json',
            'version' => '/versions/1.json',
            'categories' => '/projects/parity-core/issue_categories.json',
            'category' => '/issue_categories/1.json',
            'users' => '/users.json?limit=100',
            'user' => '/users/1.json',
            'account' => '/my/account.json',
            'time_entries' => '/time_entries.json?limit=100',
            'issue_time_entries' => '/issues/1/time_entries.json',
            'time_entry' => '/time_entries/1.json',
            'relations' => '/issues/1/relations.json',
            'relation' => '/relations/1.json',
            'trackers' => '/trackers.json',
            'tracker' => '/trackers/1.json',
            'statuses' => '/issue_statuses.json',
            'status' => '/issue_statuses/1.json',
            'priorities' => '/enumerations/issue_priorities.json',
            'activities' => '/enumerations/time_entry_activities.json',
            'document_categories' => '/enumerations/document_categories.json',
            'custom_fields' => '/custom_fields.json?limit=100',
            'custom_field' => '/custom_fields/1.json',
            'queries' => '/queries.json?limit=100',
            'query' => '/queries/1.json',
            'roles' => '/roles.json',
            'role' => '/roles/1.json',
            'groups' => '/groups.json?include=users&limit=100',
            'group' => '/groups/3.json?include=users',
            'news' => '/news.json',
            'attachment' => '/attachments/1.json',
            'journals' => '/issues/1/journals.json',
            'journal' => '/journals/1.json',
            'search' => '/search.json?q=Parity',
        ];
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function assertPrivateNoteVisibility(array $resources): void
    {
        $adminJournals = $resources['issue_include']['body']['issue']['journals'] ?? null;
        $adaJournals = $resources['issue_include_ada']['body']['issue']['journals'] ?? null;
        $this->assertIsArray($adminJournals);
        $this->assertIsArray($adaJournals);
        $adminPrivate = 0;
        foreach ($adminJournals as $journal) {
            $this->assertIsArray($journal);
            if (($journal['private_notes'] ?? false) === true) {
                $adminPrivate++;
            }
        }
        $this->assertGreaterThan(0, $adminPrivate);
        foreach ($adaJournals as $journal) {
            $this->assertIsArray($journal);
            $this->assertFalse($journal['private_notes'] ?? true);
        }
        $this->assertLessThan(count($adminJournals), count($adaJournals));
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function document(string $key, string $path): array
    {
        $response = $this->withHeader('X-Redmine-API-Key', $key)->get($path);
        $decoded = $response->json();
        $this->assertIsArray($decoded, $path.' '.$response->getContent());

        return ['status' => $response->status(), 'body' => $decoded];
    }

    private function body(string $key, string $path): string
    {
        $response = $this->withHeader('X-Redmine-API-Key', $key)->get($path);
        $response->assertOk();
        $content = $response->getContent();
        $this->assertNotFalse($content);

        return $content;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $json
     * @return array{status: int, body: array<string, mixed>|string}
     */
    private function exchange(string $method, string $path, array $headers, array $json = []): array
    {
        $request = $this->flushHeaders();
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = match ($method) {
            'POST' => $request->postJson($path, $json),
            'PUT' => $request->putJson($path, $json),
            'DELETE' => $request->deleteJson($path, $json),
            default => $request->get($path),
        };
        $this->assertInstanceOf(TestResponse::class, $response);
        if (str_ends_with(parse_url($path, PHP_URL_PATH) ?: $path, '.xml')) {
            $content = $response->getContent();
            $this->assertIsString($content);

            return ['status' => $response->status(), 'body' => $content];
        }
        $decoded = $response->json();
        $this->assertIsArray($decoded, $path.' '.$response->getContent());

        return ['status' => $response->status(), 'body' => $decoded];
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
    }

    private function assertPinned(string $path, mixed $actual): void
    {
        $full = base_path($path);
        $directory = dirname($full);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
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

    /**
     * Issued API keys are random. The pin compares the field, not the secret.
     */
    private function stabilize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $stable = [];
        foreach ($value as $key => $item) {
            if ($key === 'api_key' && is_string($item) && strlen($item) === 40) {
                $stable[$key] = 'pinned-api-key';

                continue;
            }
            $stable[$key] = $this->stabilize($item);
        }

        return $stable;
    }
}
