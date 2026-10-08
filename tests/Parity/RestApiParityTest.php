<?php

namespace Tests\Parity;

use App\Domain\Auth\ActionToken;
use App\Domain\Auth\Oauth\OauthProvider;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\SettingValue;
use App\Models\Board;
use App\Models\OauthAccessToken;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $this->assertMatchesRegularExpression('/^\| Users and authentication — live LDAP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums HTTP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki and boards visual UX \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Webhooks \| NOT VERIFIED \|.*645.*461/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Reactions \| NOT VERIFIED \|.*490/m', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/api/writes.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/api/xml.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/api/modules.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/api/oauth.json', $checklist);
    }

    public function test_writes_xml_modules_and_oauth_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $this->setting(SettingValue::REST_API_ENABLED, '1');
        $admin = $this->user('admin');
        $ada = $this->user('ada');
        $adminKey = app(ActionToken::class)->issueNamed($admin, Token::ACTION_API)->value;
        $adaKey = app(ActionToken::class)->issueNamed($ada, Token::ACTION_API)->value;

        $xml = [];
        foreach ($this->paths() as $name => $path) {
            $xml[$name] = $this->raw($adminKey, $this->asXml($path));
            $this->assertLessThan(500, $xml[$name]['status'], $name.' '.(is_string($xml[$name]['body']) ? substr($xml[$name]['body'], 0, 180) : ''));
        }
        foreach ([
            'wiki' => '/projects/parity-core/wiki/index.xml',
            'files' => '/projects/parity-core/files.xml',
            'boards' => '/projects/parity-core/boards.xml',
            'documents' => '/projects/parity-core/documents.xml',
            'news' => '/projects/parity-core/news.xml',
        ] as $name => $path) {
            $xml['disabled_'.$name] = $this->raw($adminKey, $path);
            $this->assertSame(403, $xml['disabled_'.$name]['status']);
        }
        $issueXml = $xml['issue']['body'] ?? null;
        $issuesXml = $xml['issues']['body'] ?? null;
        $this->assertIsString($issueXml);
        $this->assertIsString($issuesXml);
        $this->assertStringContainsString('type="array"', $issueXml);
        $this->assertStringContainsString('nil="true"', $issueXml);
        $this->assertStringContainsString('total_count="', $issuesXml);
        $this->assertStringContainsString('offset="', $issuesXml);
        $this->assertStringContainsString('limit="', $issuesXml);
        $this->assertPinned('tests/Parity/fixtures/redmine-7.0.1/expectations/api/xml.json', $this->stabilize($xml));

        $this->captureCreatedBaselines();
        $writes = $this->writeExchanges($adminKey, $ada);
        $this->assertPinned(
            'tests/Parity/fixtures/redmine-7.0.1/expectations/api/writes.json',
            $this->stabilizeStamps($this->stabilize($this->relabel($writes))),
        );

        $modules = $this->moduleExchanges($admin, $ada, $adminKey, $adaKey);
        $this->assertPinned(
            'tests/Parity/fixtures/redmine-7.0.1/expectations/api/modules.json',
            $this->stabilizeStamps($this->stabilize($this->relabel($modules))),
        );

        $oauth = $this->oauthExchanges($admin, $ada);
        $this->assertPinned(
            'tests/Parity/fixtures/redmine-7.0.1/expectations/api/oauth.json',
            $this->stabilizeStamps($this->stabilize($this->relabel($oauth))),
        );
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
        if (is_string($value)) {
            $replaced = preg_replace('/<api_key>[0-9a-f]{40}<\/api_key>/', '<api_key>pinned-api-key</api_key>', $value);

            return is_string($replaced) ? $replaced : $value;
        }
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

    private function stabilizeStamps(mixed $value): mixed
    {
        if (is_string($value)) {
            $replaced = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', 'pinned-stamp', $value);

            return is_string($replaced) ? $replaced : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $stable = [];
        foreach ($value as $key => $item) {
            $stable[$key] = $this->stabilizeStamps($item);
        }

        return $stable;
    }

    /**
     * @return array<string, array{status: int, location: string|null, body: mixed}>
     */
    private function writeExchanges(string $adminKey, User $ada): array
    {
        $headers = ['X-Redmine-API-Key' => $adminKey];
        $upload = $this->dispatch('POST', '/uploads.json?filename=note.txt', $headers, null, 'pin-bytes', 'application/octet-stream');
        $this->assertSame(201, $upload['status'], (string) json_encode($upload));
        $token = is_array($upload['body']) ? ($upload['body']['upload']['token'] ?? null) : null;
        $this->assertIsString($token);
        $this->assertStringStartsWith('/', (string) $upload['location'] === '' ? '/' : (string) ($upload['location'] ?? '/'));

        $issue = $this->dispatch('POST', '/issues.json', $headers, ['issue' => [
            'project_id' => 'parity-core',
            'tracker_id' => 1,
            'subject' => 'REST created',
            'watcher_user_ids' => [(int) $ada->id],
            'uploads' => [[
                'token' => $token,
                'filename' => 'note.txt',
                'content_type' => 'text/plain',
            ]],
        ]]);
        $this->assertSame(201, $issue['status'], (string) json_encode($issue));
        $this->assertIsString($issue['location']);
        $issueId = $this->resourceId($issue, 'issue');
        $shown = $this->dispatch('GET', '/issues/'.$issueId.'.json?include=attachments,watchers', $headers);
        $this->assertSame(200, $shown['status']);

        $updated = $this->dispatch('PUT', '/issues/'.$issueId.'.json', $headers, ['issue' => ['subject' => 'REST updated']]);
        $this->assertSame(204, $updated['status']);
        $relation = $this->dispatch('POST', '/issues/'.$issueId.'/relations.json', $headers, ['relation' => [
            'issue_to_id' => 1,
            'relation_type' => 'relates',
        ]]);
        $this->assertSame(201, $relation['status'], (string) json_encode($relation));
        $relationId = $this->resourceId($relation, 'relation');
        $relationDeleted = $this->dispatch('DELETE', '/relations/'.$relationId.'.json', $headers);
        $this->assertSame(204, $relationDeleted['status']);
        $watched = $this->dispatch('POST', '/issues/'.$issueId.'/watchers.json', $headers, ['user_id' => 2]);
        $this->assertSame(204, $watched['status'], (string) json_encode($watched));
        $unwatched = $this->dispatch('DELETE', '/issues/'.$issueId.'/watchers/2.json', $headers);
        $this->assertSame(204, $unwatched['status']);
        $issueDeleted = $this->dispatch('DELETE', '/issues/'.$issueId.'.json', $headers);
        $this->assertSame(204, $issueDeleted['status'], (string) json_encode($issueDeleted));

        $xmlUpload = $this->dispatch('POST', '/uploads.xml?filename=note.xml.txt', $headers, null, 'xml-bytes', 'application/octet-stream');
        $this->assertSame(201, $xmlUpload['status'], is_string($xmlUpload['body']) ? $xmlUpload['body'] : '');
        $missingName = $this->dispatch('POST', '/uploads.json', $headers, null, 'pin-bytes', 'application/octet-stream');
        $this->assertSame(422, $missingName['status']);
        $wrongType = $this->dispatch('POST', '/uploads.json?filename=note.txt', $headers, ['unused' => true]);
        $this->assertSame(422, $wrongType['status']);
        $empty = $this->dispatch('POST', '/uploads.json?filename=empty.txt', $headers, null, '', 'application/octet-stream');
        $this->assertSame(422, $empty['status']);
        $this->setting(SettingValue::ATTACHMENT_MAX_SIZE, '1');
        $tooLarge = $this->dispatch('POST', '/uploads.json?filename=big.bin', $headers, null, str_repeat('a', 2048), 'application/octet-stream');
        $this->assertSame(413, $tooLarge['status'], (string) json_encode($tooLarge));
        $this->setting(SettingValue::ATTACHMENT_MAX_SIZE, '5120');
        $this->setting(SettingValue::ATTACHMENT_EXTENSIONS_DENIED, '["exe"]');
        $denied = $this->dispatch('POST', '/uploads.json?filename=bad.exe', $headers, null, 'exe-bytes', 'application/octet-stream');
        $this->assertSame(422, $denied['status'], (string) json_encode($denied));
        Setting::query()->where('name', SettingValue::ATTACHMENT_EXTENSIONS_DENIED)->delete();

        $xmlIssue = $this->dispatch(
            'POST',
            '/issues.xml',
            $headers,
            null,
            '<?xml version="1.0"?><issue><project_id>parity-core</project_id><tracker_id>1</tracker_id><subject>REST xml</subject></issue>',
            'application/xml',
        );
        $this->assertSame(201, $xmlIssue['status'], is_string($xmlIssue['body']) ? $xmlIssue['body'] : (string) json_encode($xmlIssue));

        $project = $this->dispatch('POST', '/projects.json', $headers, ['project' => [
            'name' => 'Parity REST',
            'identifier' => 'parity-rest',
            'description' => 'Created by the REST pin',
        ]]);
        $this->assertSame(201, $project['status'], (string) json_encode($project));
        $this->assertSame('/projects/parity-rest.json', $project['location']);
        $projectUpdated = $this->dispatch('PUT', '/projects/parity-rest.json', $headers, ['project' => ['name' => 'Parity REST renamed']]);
        $this->assertSame(204, $projectUpdated['status']);
        $membership = $this->dispatch('POST', '/projects/parity-rest/memberships.json', $headers, ['membership' => [
            'user_id' => (int) $ada->id,
            'role_ids' => [1],
        ]]);
        $this->assertSame(201, $membership['status'], (string) json_encode($membership));
        $membershipId = $this->resourceId($membership, 'membership');
        $membershipUpdated = $this->dispatch('PUT', '/memberships/'.$membershipId.'.json', $headers, ['membership' => ['role_ids' => [2]]]);
        $this->assertSame(204, $membershipUpdated['status'], (string) json_encode($membershipUpdated));
        $membershipDeleted = $this->dispatch('DELETE', '/memberships/'.$membershipId.'.json', $headers);
        $this->assertSame(204, $membershipDeleted['status'], (string) json_encode($membershipDeleted));

        $version = $this->dispatch('POST', '/projects/parity-rest/versions.json', $headers, ['version' => ['name' => 'rest-1']]);
        $this->assertSame(201, $version['status'], (string) json_encode($version));
        $versionId = $this->resourceId($version, 'version');
        $versionUpdated = $this->dispatch('PUT', '/versions/'.$versionId.'.json', $headers, ['version' => ['name' => 'rest-1b']]);
        $this->assertSame(204, $versionUpdated['status']);
        $versionDeleted = $this->dispatch('DELETE', '/versions/'.$versionId.'.json', $headers);
        $this->assertSame(204, $versionDeleted['status']);

        $category = $this->dispatch('POST', '/projects/parity-rest/issue_categories.json', $headers, ['issue_category' => ['name' => 'REST category']]);
        $this->assertSame(201, $category['status'], (string) json_encode($category));
        $categoryId = $this->resourceId($category, 'issue_category');
        $categoryUpdated = $this->dispatch('PUT', '/issue_categories/'.$categoryId.'.json', $headers, ['issue_category' => ['name' => 'REST category renamed']]);
        $this->assertSame(204, $categoryUpdated['status']);
        $categoryDeleted = $this->dispatch('DELETE', '/issue_categories/'.$categoryId.'.json', $headers);
        $this->assertSame(204, $categoryDeleted['status']);

        $closed = $this->dispatch('PUT', '/projects/parity-rest/close.json', $headers);
        $this->assertSame(204, $closed['status'], (string) json_encode($closed));
        $closedWrite = $this->dispatch('PUT', '/projects/parity-rest.json', $headers, ['project' => ['name' => 'Should fail']]);
        $this->assertSame(403, $closedWrite['status']);
        $reopened = $this->dispatch('PUT', '/projects/parity-rest/reopen.json', $headers);
        $this->assertSame(204, $reopened['status']);
        $archived = $this->dispatch('PUT', '/projects/parity-rest/archive.json', $headers);
        $this->assertSame(204, $archived['status'], (string) json_encode($archived));
        $unarchived = $this->dispatch('PUT', '/projects/parity-rest/unarchive.json', $headers);
        $this->assertSame(204, $unarchived['status'], (string) json_encode($unarchived));
        $projectDeleted = $this->dispatch('DELETE', '/projects/parity-rest.json', $headers);
        $this->assertSame(204, $projectDeleted['status'], (string) json_encode($projectDeleted));

        $entry = $this->dispatch('POST', '/time_entries.json', $headers, ['time_entry' => [
            'issue_id' => 1,
            'hours' => 1.25,
            'spent_on' => '2026-10-01',
            'activity_id' => 3,
            'comments' => 'REST pin',
        ]]);
        $this->assertSame(201, $entry['status'], (string) json_encode($entry));
        $entryId = $this->resourceId($entry, 'time_entry');
        $entryUpdated = $this->dispatch('PUT', '/time_entries/'.$entryId.'.json', $headers, ['time_entry' => ['hours' => 2]]);
        $this->assertSame(204, $entryUpdated['status']);
        $entryDeleted = $this->dispatch('DELETE', '/time_entries/'.$entryId.'.json', $headers);
        $this->assertSame(204, $entryDeleted['status']);

        $user = $this->dispatch('POST', '/users.json', $headers, ['user' => [
            'login' => 'rest-user',
            'firstname' => 'Rest',
            'lastname' => 'User',
            'mail' => 'rest-user@example.test',
            'password' => 'Parity-pass-1',
        ]]);
        $this->assertSame(201, $user['status'], (string) json_encode($user));
        $userId = $this->resourceId($user, 'user');
        $userUpdated = $this->dispatch('PUT', '/users/'.$userId.'.json', $headers, ['user' => ['lastname' => 'Person']]);
        $this->assertSame(204, $userUpdated['status']);
        $userDeleted = $this->dispatch('DELETE', '/users/'.$userId.'.json', $headers);
        $this->assertSame(204, $userDeleted['status'], (string) json_encode($userDeleted));

        $group = $this->dispatch('POST', '/groups.json', $headers, ['group' => ['name' => 'REST readers']]);
        $this->assertSame(201, $group['status'], (string) json_encode($group));
        $groupId = $this->resourceId($group, 'group');
        $groupUser = $this->dispatch('POST', '/groups/'.$groupId.'/users.json', $headers, ['user_id' => (int) $ada->id]);
        $this->assertSame(204, $groupUser['status'], (string) json_encode($groupUser));
        $groupUserRemoved = $this->dispatch('DELETE', '/groups/'.$groupId.'/users/'.$ada->id.'.json', $headers);
        $this->assertSame(204, $groupUserRemoved['status']);
        $groupUpdated = $this->dispatch('PUT', '/groups/'.$groupId.'.json', $headers, ['group' => ['name' => 'REST readers renamed']]);
        $this->assertSame(204, $groupUpdated['status']);
        $groupDeleted = $this->dispatch('DELETE', '/groups/'.$groupId.'.json', $headers);
        $this->assertSame(204, $groupDeleted['status'], (string) json_encode($groupDeleted));

        $account = $this->dispatch('PUT', '/my/account.json', $headers, ['user' => ['firstname' => 'Restadmin']]);
        $this->assertSame(204, $account['status'], (string) json_encode($account));
        $accountShown = $this->dispatch('GET', '/my/account.json', $headers);
        $this->assertSame(200, $accountShown['status']);

        return [
            'upload' => $upload,
            'issue' => $issue,
            'issue_shown' => $shown,
            'issue_updated' => $updated,
            'relation' => $relation,
            'relation_deleted' => $relationDeleted,
            'watcher_added' => $watched,
            'watcher_removed' => $unwatched,
            'issue_deleted' => $issueDeleted,
            'upload_xml' => $xmlUpload,
            'upload_missing_filename' => $missingName,
            'upload_wrong_type' => $wrongType,
            'upload_empty' => $empty,
            'upload_too_large' => $tooLarge,
            'upload_denied_extension' => $denied,
            'issue_xml' => $xmlIssue,
            'project' => $project,
            'project_updated' => $projectUpdated,
            'membership' => $membership,
            'membership_updated' => $membershipUpdated,
            'membership_deleted' => $membershipDeleted,
            'version' => $version,
            'version_updated' => $versionUpdated,
            'version_deleted' => $versionDeleted,
            'category' => $category,
            'category_updated' => $categoryUpdated,
            'category_deleted' => $categoryDeleted,
            'project_closed' => $closed,
            'project_closed_write' => $closedWrite,
            'project_reopened' => $reopened,
            'project_archived' => $archived,
            'project_unarchived' => $unarchived,
            'project_deleted' => $projectDeleted,
            'time_entry' => $entry,
            'time_entry_updated' => $entryUpdated,
            'time_entry_deleted' => $entryDeleted,
            'user' => $user,
            'user_updated' => $userUpdated,
            'user_deleted' => $userDeleted,
            'group' => $group,
            'group_user_added' => $groupUser,
            'group_user_removed' => $groupUserRemoved,
            'group_updated' => $groupUpdated,
            'group_deleted' => $groupDeleted,
            'account_updated' => $account,
            'account_shown' => $accountShown,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleExchanges(User $admin, User $ada, string $adminKey, string $adaKey): array
    {
        $projects = app(ProjectService::class);
        $project = Project::query()->where('identifier', 'parity-core')->first();
        $this->assertInstanceOf(Project::class, $project);
        foreach (['wiki', 'news', 'files', 'boards', 'documents'] as $module) {
            $projects->enableModule($project, $module);
        }
        $adminHeaders = ['X-Redmine-API-Key' => $adminKey];
        $adaHeaders = ['X-Redmine-API-Key' => $adaKey];

        $wiki = $this->dispatch('PUT', '/projects/parity-core/wiki/RestGuide.json', $adminHeaders, ['wiki_page' => [
            'text' => 'first page',
            'comments' => 'created',
        ]]);
        $this->assertSame(201, $wiki['status'], (string) json_encode($wiki));
        $wikiUpdated = $this->dispatch('PUT', '/projects/parity-core/wiki/RestGuide.json', $adminHeaders, ['wiki_page' => [
            'text' => 'second page',
            'comments' => 'updated',
            'version' => 1,
        ]]);
        $this->assertSame(204, $wikiUpdated['status'], (string) json_encode($wikiUpdated));
        $wikiConflict = $this->dispatch('PUT', '/projects/parity-core/wiki/RestGuide.json', $adminHeaders, ['wiki_page' => [
            'text' => 'stale',
            'version' => 1,
        ]]);
        $this->assertSame(409, $wikiConflict['status']);
        $wikiShown = $this->dispatch('GET', '/projects/parity-core/wiki/RestGuide.json', $adminHeaders);
        $wikiXml = $this->dispatch('GET', '/projects/parity-core/wiki/RestGuide.xml', $adminHeaders);
        $this->assertSame(200, $wikiShown['status']);

        $news = $this->dispatch('POST', '/projects/parity-core/news.json', $adminHeaders, ['news' => [
            'title' => 'REST note',
            'summary' => 'short',
            'description' => 'body',
        ]]);
        $this->assertSame(201, $news['status'], (string) json_encode($news));
        $newsId = $this->resourceId($news, 'news');
        $newsUpdated = $this->dispatch('PUT', '/news/'.$newsId.'.json', $adminHeaders, ['news' => ['title' => 'REST note renamed']]);
        $newsShown = $this->dispatch('GET', '/news/'.$newsId.'.json', $adminHeaders);
        $newsXml = $this->dispatch('GET', '/news/'.$newsId.'.xml', $adminHeaders);

        $fileUpload = $this->dispatch('POST', '/uploads.json?filename=readme.txt', $adminHeaders, null, 'file-bytes', 'application/octet-stream');
        $this->assertSame(201, $fileUpload['status'], (string) json_encode($fileUpload));
        $fileToken = is_array($fileUpload['body']) ? ($fileUpload['body']['upload']['token'] ?? null) : null;
        $this->assertIsString($fileToken);
        $file = $this->dispatch('POST', '/projects/parity-core/files.json', $adminHeaders, ['file' => [
            'token' => $fileToken,
            'filename' => 'readme.txt',
            'description' => 'pin file',
        ]]);
        $this->assertSame(201, $file['status'], (string) json_encode($file));
        $files = $this->dispatch('GET', '/projects/parity-core/files.json', $adminHeaders);
        $filesXml = $this->dispatch('GET', '/projects/parity-core/files.xml', $adminHeaders);

        $board = Board::query()->create([
            'project_id' => $project->id,
            'name' => 'REST',
            'description' => 'Parity board',
            'position' => 1,
        ]);
        $boards = $this->dispatch('GET', '/projects/parity-core/boards.json', $adminHeaders);
        $topic = $this->dispatch('POST', '/boards/'.$board->id.'/topics.json', $adminHeaders, ['message' => [
            'subject' => 'Admin topic',
            'content' => 'from admin',
        ]]);
        $this->assertSame(201, $topic['status'], (string) json_encode($topic));
        $topicId = $this->resourceId($topic, 'message');
        $reply = $this->dispatch('POST', '/messages/'.$topicId.'/replies.json', $adminHeaders, ['message' => [
            'content' => 'a reply',
        ]]);
        $this->assertSame(201, $reply['status'], (string) json_encode($reply));
        $replyId = $this->resourceId($reply, 'message');
        $messageShown = $this->dispatch('GET', '/messages/'.$topicId.'.json', $adminHeaders);
        $messageXml = $this->dispatch('GET', '/messages/'.$topicId.'.xml', $adminHeaders);

        $document = $this->dispatch('POST', '/projects/parity-core/documents.json', $adminHeaders, ['document' => [
            'title' => 'REST guide',
            'category_id' => 4,
            'description' => 'pin document',
        ]]);
        $this->assertSame(201, $document['status'], (string) json_encode($document));
        $documentId = $this->resourceId($document, 'document');
        $documentUpdated = $this->dispatch('PUT', '/documents/'.$documentId.'.json', $adminHeaders, ['document' => ['title' => 'REST guide renamed']]);
        $this->assertSame(204, $documentUpdated['status']);
        $documents = $this->dispatch('GET', '/projects/parity-core/documents.json', $adminHeaders);
        $documentXml = $this->dispatch('GET', '/documents/'.$documentId.'.xml', $adminHeaders);

        $role = Role::query()->find(1);
        $this->assertInstanceOf(Role::class, $role);
        $current = $role->permissions;
        $this->assertIsArray($current);
        $role->permissions = array_values(array_unique([
            ...$current,
            'view_wiki_pages',
            'view_news',
            'view_files',
            'view_messages',
            'add_messages',
            'edit_own_messages',
            'delete_own_messages',
            'view_documents',
        ]));
        $role->save();

        $adaCannotEdit = $this->dispatch('PUT', '/messages/'.$topicId.'.json', $adaHeaders, ['message' => ['subject' => 'Taken']]);
        $this->assertSame(403, $adaCannotEdit['status']);
        $adaCannotDelete = $this->dispatch('DELETE', '/messages/'.$topicId.'.json', $adaHeaders);
        $this->assertSame(403, $adaCannotDelete['status']);
        $ownTopic = $this->dispatch('POST', '/boards/'.$board->id.'/topics.json', $adaHeaders, ['message' => [
            'subject' => 'Own topic',
            'content' => 'from ada',
        ]]);
        $this->assertSame(201, $ownTopic['status'], (string) json_encode($ownTopic));
        $ownId = $this->resourceId($ownTopic, 'message');
        $ownUpdated = $this->dispatch('PUT', '/messages/'.$ownId.'.json', $adaHeaders, ['message' => ['subject' => 'Own topic edited']]);
        $this->assertSame(200, $ownUpdated['status'], (string) json_encode($ownUpdated));
        $ownDeleted = $this->dispatch('DELETE', '/messages/'.$ownId.'.json', $adaHeaders);
        $this->assertSame(204, $ownDeleted['status']);

        $project->status = Project::STATUS_CLOSED;
        $project->save();
        $closedRead = $this->dispatch('GET', '/projects/parity-core/wiki/RestGuide.json', $adaHeaders);
        $this->assertSame(200, $closedRead['status']);
        $closedWrite = $this->dispatch('PUT', '/projects/parity-core/wiki/RestGuide.json', $adminHeaders, ['wiki_page' => ['text' => 'closed']]);
        $this->assertSame(403, $closedWrite['status']);
        $closedNews = $this->dispatch('POST', '/projects/parity-core/news.json', $adminHeaders, ['news' => ['title' => 'Closed']]);
        $this->assertSame(403, $closedNews['status']);
        $closedDocument = $this->dispatch('PUT', '/documents/'.$documentId.'.json', $adminHeaders, ['document' => ['title' => 'Closed']]);
        $this->assertSame(403, $closedDocument['status']);
        $project->status = Project::STATUS_ACTIVE;
        $project->save();
        $projects->enableModule($project->refresh(), 'wiki');
        $projects->enableModule($project, 'news');
        $projects->enableModule($project, 'boards');
        $projects->enableModule($project, 'documents');

        $wikiDeleted = $this->dispatch('DELETE', '/projects/parity-core/wiki/RestGuide.json', $adminHeaders);
        $this->assertSame(204, $wikiDeleted['status'], (string) json_encode($wikiDeleted));
        $newsDeleted = $this->dispatch('DELETE', '/news/'.$newsId.'.json', $adminHeaders);
        $this->assertSame(204, $newsDeleted['status'], (string) json_encode($newsDeleted));
        $replyDeleted = $this->dispatch('DELETE', '/messages/'.$replyId.'.json', $adminHeaders);
        $this->assertSame(204, $replyDeleted['status'], (string) json_encode($replyDeleted));
        $topicDeleted = $this->dispatch('DELETE', '/messages/'.$topicId.'.json', $adminHeaders);
        $this->assertSame(204, $topicDeleted['status'], (string) json_encode($topicDeleted));
        $documentDeleted = $this->dispatch('DELETE', '/documents/'.$documentId.'.json', $adminHeaders);
        $this->assertSame(204, $documentDeleted['status'], (string) json_encode($documentDeleted));

        $disabled = [];
        foreach (['wiki' => '/projects/parity-core/wiki/index.json', 'news' => '/projects/parity-core/news.json', 'files' => '/projects/parity-core/files.json', 'boards' => '/projects/parity-core/boards.json', 'documents' => '/projects/parity-core/documents.json'] as $module => $path) {
            $projects->disableModule($project->refresh(), $module);
            $disabled[$module] = $this->dispatch('GET', $path, $adminHeaders);
            $this->assertSame(403, $disabled[$module]['status'], $module);
        }

        return [
            'wiki_created' => $wiki,
            'wiki_updated' => $wikiUpdated,
            'wiki_conflict' => $wikiConflict,
            'wiki_shown' => $wikiShown,
            'wiki_xml' => $wikiXml,
            'news_created' => $news,
            'news_updated' => $newsUpdated,
            'news_shown' => $newsShown,
            'news_xml' => $newsXml,
            'files' => $files,
            'files_xml' => $filesXml,
            'file_created' => $file,
            'boards' => $boards,
            'topic' => $topic,
            'reply' => $reply,
            'message_shown' => $messageShown,
            'message_xml' => $messageXml,
            'document_created' => $document,
            'document_updated' => $documentUpdated,
            'documents' => $documents,
            'document_xml' => $documentXml,
            'edit_own_denied' => $adaCannotEdit,
            'delete_own_denied' => $adaCannotDelete,
            'edit_own_allowed' => $ownUpdated,
            'delete_own_allowed' => $ownDeleted,
            'closed_read' => $closedRead,
            'closed_wiki_write' => $closedWrite,
            'closed_news_write' => $closedNews,
            'closed_document_write' => $closedDocument,
            'module_disabled' => $disabled,
            'wiki_deleted' => $wikiDeleted,
            'news_deleted' => $newsDeleted,
            'reply_deleted' => $replyDeleted,
            'topic_deleted' => $topicDeleted,
            'document_deleted' => $documentDeleted,
        ];
    }

    /**
     * @return array<string, array{status: int, location: string|null, body: mixed}>
     */
    private function oauthExchanges(User $admin, User $ada): array
    {
        $registered = app(OauthProvider::class)->registerApplication(
            $admin,
            'REST pin',
            'https://parity.example/callback',
            'view_issues add_issues edit_issues admin',
            true,
        );
        $applicationId = (int) $registered['application']->id;
        $this->bearer($applicationId, (int) $ada->id, 'ada-view', 'view_issues', false, false);
        $this->bearer($applicationId, (int) $admin->id, 'admin-view', 'view_issues', false, false);
        $this->bearer($applicationId, (int) $admin->id, 'admin-full', 'admin view_issues', false, false);
        $this->bearer($applicationId, (int) $ada->id, 'ada-blank', '', false, false);
        $this->bearer($applicationId, (int) $ada->id, 'ada-expired', 'view_issues', true, false);
        $this->bearer($applicationId, (int) $ada->id, 'ada-revoked', 'view_issues', false, true);

        $viewGet = $this->dispatch('GET', '/issues.json?project_id=parity-core&limit=1', ['Authorization' => 'Bearer ada-view']);
        $this->assertSame(200, $viewGet['status'], (string) json_encode($viewGet));
        $viewPost = $this->dispatch('POST', '/issues.json', ['Authorization' => 'Bearer ada-view'], ['issue' => [
            'project_id' => 'parity-core',
            'tracker_id' => 1,
            'subject' => 'scope denied',
        ]]);
        $this->assertSame(403, $viewPost['status'], (string) json_encode($viewPost));
        $adminUsers = $this->dispatch('GET', '/users.json?limit=1', ['Authorization' => 'Bearer admin-view']);
        $this->assertSame(403, $adminUsers['status']);
        $adminEdit = $this->dispatch('PUT', '/issues/1.json', ['Authorization' => 'Bearer admin-view'], ['issue' => ['subject' => 'scope edit']]);
        $this->assertSame(403, $adminEdit['status'], (string) json_encode($adminEdit));
        $adminScope = $this->dispatch('GET', '/users.json?limit=1', ['Authorization' => 'Bearer admin-full']);
        $this->assertSame(200, $adminScope['status'], (string) json_encode($adminScope));
        $blank = $this->dispatch('POST', '/issues.json', ['Authorization' => 'Bearer ada-blank'], ['issue' => [
            'project_id' => 'parity-core',
            'tracker_id' => 1,
            'subject' => 'blank scope',
            'estimated_hours' => 1,
        ]]);
        $this->assertSame(201, $blank['status'], (string) json_encode($blank));
        $expired = $this->dispatch('GET', '/issues.json', ['Authorization' => 'Bearer ada-expired']);
        $this->assertSame(401, $expired['status']);
        $revoked = $this->dispatch('GET', '/issues.json', ['Authorization' => 'Bearer ada-revoked']);
        $this->assertSame(401, $revoked['status']);
        $this->setting(SettingValue::REST_API_ENABLED, '0');
        $disabled = $this->dispatch('GET', '/issues.json', ['Authorization' => 'Bearer ada-view']);
        $this->assertSame(401, $disabled['status']);

        return [
            'view_scope_get' => $viewGet,
            'view_scope_post' => $viewPost,
            'admin_without_admin_scope' => $adminUsers,
            'admin_edit_without_scope' => $adminEdit,
            'admin_scope_users' => $adminScope,
            'blank_scope_post' => $blank,
            'expired' => $expired,
            'revoked' => $revoked,
            'rest_disabled' => $disabled,
        ];
    }

    private function bearer(int $applicationId, int $userId, string $token, string $scopes, bool $expired, bool $revoked): void
    {
        OauthAccessToken::query()->create([
            'application_id' => $applicationId,
            'resource_owner_id' => $userId,
            'token' => $token,
            'refresh_token' => $token.'-refresh',
            'expires_in' => $expired ? 60 : 7200,
            'scopes' => $scopes,
            'previous_refresh_token' => '',
            'created_at' => $expired ? Carbon::now()->subHours(3) : Carbon::now(),
            'revoked_at' => $revoked ? Carbon::now() : null,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     * @return array{status: int, location: string|null, body: mixed}
     */
    private function dispatch(string $method, string $path, array $headers, ?array $json = null, ?string $raw = null, ?string $contentType = null): array
    {
        $pathOnly = parse_url($path, PHP_URL_PATH);
        $isXml = is_string($pathOnly) && str_ends_with($pathOnly, '.xml');
        $server = ['HTTP_ACCEPT' => $isXml ? 'application/xml' : 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $content = $raw;
        if ($json !== null) {
            $encoded = json_encode($json);
            $this->assertIsString($encoded);
            $content = $encoded;
            $contentType = $contentType ?? 'application/json';
        }
        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        $response = $this->call($method, $path, [], [], [], $server, $content);
        $header = $response->headers->get('Location');
        $location = null;
        if (is_string($header) && $header !== '') {
            $parsed = parse_url($header, PHP_URL_PATH);
            $location = is_string($parsed) ? $parsed : $header;
        }
        $body = $response->getContent();
        $this->assertIsString($body);
        if ($response->status() === 204 || $body === '') {
            return ['status' => $response->status(), 'location' => $location, 'body' => null];
        }
        if ($isXml) {
            return ['status' => $response->status(), 'location' => $location, 'body' => $body];
        }
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, $method.' '.$path.' '.$body);

        return ['status' => $response->status(), 'location' => $location, 'body' => $decoded];
    }

    /**
     * @return array{status: int, location: string|null, body: mixed}
     */
    private function raw(string $key, string $path): array
    {
        return $this->dispatch('GET', $path, ['X-Redmine-API-Key' => $key]);
    }

    private function asXml(string $path): string
    {
        $replaced = preg_replace('/\.json/', '.xml', $path, 1);

        return is_string($replaced) ? $replaced : $path;
    }

    /**
     * Highest fixture id per table before this test inserts rows.
     *
     * Later tests in the same process leave MySQL AUTO_INCREMENT above the
     * rolled-back rows. Created ids are labeled from these ceilings so the
     * pin does not depend on suite order.
     *
     * @var array<string, int>
     */
    private array $ceilings = [];

    /**
     * @var array<string, array<int, string>>
     */
    private array $createdLabels = [];

    /**
     * @var array<string, string>
     */
    private const ID_HINTS = [
        'issue' => 'issues',
        'issues' => 'issues',
        'relation' => 'issue_relations',
        'relations' => 'issue_relations',
        'project' => 'projects',
        'projects' => 'projects',
        'membership' => 'members',
        'memberships' => 'members',
        'version' => 'versions',
        'versions' => 'versions',
        'issue_category' => 'issue_categories',
        'issue_categories' => 'issue_categories',
        'time_entry' => 'time_entries',
        'time_entries' => 'time_entries',
        'user' => 'users',
        'users' => 'users',
        'group' => 'users',
        'groups' => 'users',
        'news' => 'news',
        'message' => 'messages',
        'messages' => 'messages',
        'document' => 'documents',
        'documents' => 'documents',
        'file' => 'attachments',
        'files' => 'attachments',
        'attachment' => 'attachments',
        'attachments' => 'attachments',
        'board' => 'boards',
        'boards' => 'boards',
        'custom_field' => 'custom_fields',
        'custom_fields' => 'custom_fields',
        'tracker' => 'trackers',
        'trackers' => 'trackers',
        'role' => 'roles',
        'roles' => 'roles',
        'author' => 'users',
        'assigned_to' => 'users',
        'category' => 'enumerations',
        'priority' => 'enumerations',
        'status' => 'issue_statuses',
        'watcher' => 'users',
        'watchers' => 'users',
    ];

    /**
     * @var array<string, string>
     */
    private const PATH_TABLES = [
        'issues' => 'issues',
        'relations' => 'issue_relations',
        'memberships' => 'members',
        'versions' => 'versions',
        'issue_categories' => 'issue_categories',
        'time_entries' => 'time_entries',
        'users' => 'users',
        'groups' => 'users',
        'news' => 'news',
        'messages' => 'messages',
        'documents' => 'documents',
        'attachments' => 'attachments',
    ];

    /**
     * @var array<string, string>
     */
    private const XML_ROOTS = [
        'issue' => 'issues',
        'project' => 'projects',
        'membership' => 'members',
        'version' => 'versions',
        'issue_category' => 'issue_categories',
        'time_entry' => 'time_entries',
        'user' => 'users',
        'group' => 'users',
        'news' => 'news',
        'message' => 'messages',
        'document' => 'documents',
        'file' => 'attachments',
        'attachment' => 'attachments',
        'relation' => 'issue_relations',
        'board' => 'boards',
    ];

    private function captureCreatedBaselines(): void
    {
        foreach ([
            'issues',
            'projects',
            'members',
            'versions',
            'issue_categories',
            'time_entries',
            'users',
            'attachments',
            'issue_relations',
            'boards',
            'messages',
            'news',
            'documents',
            'custom_fields',
            'trackers',
            'roles',
            'enumerations',
            'issue_statuses',
        ] as $table) {
            $max = DB::table($table)->max('id');
            $this->ceilings[$table] = is_numeric($max) ? (int) $max : 0;
        }
    }

    private function isCreatedId(string $table, int $id): bool
    {
        return $id > ($this->ceilings[$table] ?? 0);
    }

    private function createdLabel(string $table, int $id): string
    {
        if (! isset($this->createdLabels[$table][$id])) {
            $this->createdLabels[$table][$id] = $table.'-'.(count($this->createdLabels[$table] ?? []) + 1);
        }

        return $this->createdLabels[$table][$id];
    }

    private function relabel(mixed $value, ?string $hint = null): mixed
    {
        if (is_string($value)) {
            return $this->relabelString($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $labeled = [];
        foreach ($value as $key => $item) {
            $next = is_string($key) && isset(self::ID_HINTS[$key]) ? self::ID_HINTS[$key] : $hint;
            if ($key === 'id' && is_int($item) && is_string($hint) && $this->isCreatedId($hint, $item)) {
                $labeled[$key] = $this->createdLabel($hint, $item);

                continue;
            }
            if ($key === 'issue_id' && is_int($item) && $this->isCreatedId('issues', $item)) {
                $labeled[$key] = $this->createdLabel('issues', $item);

                continue;
            }
            if ($key === 'issue_to_id' && is_int($item) && $this->isCreatedId('issues', $item)) {
                $labeled[$key] = $this->createdLabel('issues', $item);

                continue;
            }
            if ($key === 'board_id' && is_int($item) && $this->isCreatedId('boards', $item)) {
                $labeled[$key] = $this->createdLabel('boards', $item);

                continue;
            }
            if ($key === 'parent_id' && is_int($item) && $hint === 'messages' && $this->isCreatedId('messages', $item)) {
                $labeled[$key] = $this->createdLabel('messages', $item);

                continue;
            }
            $labeled[$key] = $this->relabel($item, $next);
        }

        return $labeled;
    }

    private function relabelString(string $value): string
    {
        $paths = preg_replace_callback(
            '#/(issues|relations|memberships|versions|issue_categories|time_entries|users|groups|news|messages|documents|attachments)/(\d+)#',
            function (array $match): string {
                $table = self::PATH_TABLES[$match[1]] ?? null;
                $id = (int) $match[2];
                if (! is_string($table) || ! $this->isCreatedId($table, $id)) {
                    return $match[0];
                }

                return '/'.$match[1].'/'.$this->createdLabel($table, $id);
            },
            $value,
        );
        $value = is_string($paths) ? $paths : $value;
        $roots = preg_replace_callback(
            '#<(issue|project|membership|version|issue_category|time_entry|user|group|news|message|document|file|attachment|relation|board)><id>(\d+)</id>#',
            function (array $match): string {
                $table = self::XML_ROOTS[$match[1]] ?? null;
                $id = (int) $match[2];
                if (! is_string($table) || ! $this->isCreatedId($table, $id)) {
                    return $match[0];
                }

                return '<'.$match[1].'><id>'.$this->createdLabel($table, $id).'</id>';
            },
            $value,
        );
        $value = is_string($roots) ? $roots : $value;
        $fields = preg_replace_callback(
            '#<(board_id|issue_id|issue_to_id)>(\d+)</\1>#',
            function (array $match): string {
                $table = $match[1] === 'board_id' ? 'boards' : 'issues';
                $id = (int) $match[2];
                if (! $this->isCreatedId($table, $id)) {
                    return $match[0];
                }

                return '<'.$match[1].'>'.$this->createdLabel($table, $id).'</'.$match[1].'>';
            },
            $value,
        );
        $value = is_string($fields) ? $fields : $value;
        $tokens = preg_replace_callback(
            '#\b(\d+)\.([a-f0-9]{64})\b#',
            function (array $match): string {
                $id = (int) $match[1];
                if (! $this->isCreatedId('attachments', $id)) {
                    return $match[0];
                }

                return $this->createdLabel('attachments', $id).'.'.$match[2];
            },
            $value,
        );

        return is_string($tokens) ? $tokens : $value;
    }

    /**
     * @param  array{status: int, location: string|null, body: mixed}  $exchange
     */
    private function resourceId(array $exchange, string $key): int
    {
        $body = $exchange['body'];
        $this->assertIsArray($body);
        $row = $body[$key] ?? null;
        $this->assertIsArray($row);
        $id = $row['id'] ?? null;
        $this->assertIsInt($id);

        return $id;
    }
}
