<?php

namespace Tests\Parity;

use App\Domain\Acl\PermissionService;
use App\Domain\Documents\DocumentService;
use App\Domain\DomainException;
use App\Domain\Files\ProjectFileService;
use App\Domain\News\NewsService;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Document;
use App\Models\News;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use App\Models\Watcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares news, documents, and files to the shared 7.0.1 pin.
 *
 * Wiki, boards, calendar, and gantt stay out. Repository, git, and SCM stay out.
 */
class ModulesNewsDocumentsFilesParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_matrix_matches_the_pin(): void
    {
        $expected = $this->boot();
        $permissions = app(PermissionService::class);

        foreach ($this->cases($expected, 'permissions') as $case) {
            $login = $this->login($case);
            $project = $this->project($this->intField($case, 'project_id'));
            $permission = $this->stringField($case, 'permission');
            $label = ($login ?? 'guest').' '.$permission.' project '.$project->id;
            $this->assertSame(
                $this->boolField($case, 'allowed'),
                $permissions->allowed($this->actor($login), $permission, $project),
                $label,
            );
        }

        $project = $this->project(1);
        $project->status = Project::STATUS_CLOSED;
        $project->save();
        foreach ($this->cases($expected, 'closed_project_1') as $case) {
            $login = $this->login($case);
            $permission = $this->stringField($case, 'permission');
            $this->assertSame(
                $this->boolField($case, 'allowed'),
                $permissions->allowed($this->actor($login), $permission, $project->fresh() ?? $project),
                'closed '.($login ?? 'guest').' '.$permission,
            );
        }

        $project->status = Project::STATUS_ARCHIVED;
        $project->save();
        foreach ($this->cases($expected, 'archived_project_1') as $case) {
            $login = $this->login($case);
            $permission = $this->stringField($case, 'permission');
            $this->assertSame(
                $this->boolField($case, 'allowed'),
                $permissions->allowed($this->actor($login), $permission, $project->fresh() ?? $project),
                'archived '.($login ?? 'guest').' '.$permission,
            );
        }
    }

    public function test_news_listing_comments_and_watchers_match_the_pin(): void
    {
        $expected = $this->boot();
        $news = app(NewsService::class);
        $spec = $expected['news'];
        $this->assertIsArray($spec);

        foreach ($this->listCases($spec, 'project_lists') as $case) {
            $login = $this->login($case);
            $project = $this->project($this->intField($case, 'project_id'));
            $label = 'project '.($login ?? 'guest').' '.$project->id;
            if (array_key_exists('error', $case)) {
                $this->assertDenied(fn () => $news->visibleIds($this->actor($login), $project), $this->stringField($case, 'error'), $label);

                continue;
            }
            $this->assertSame($this->intList($case, 'ids'), $news->visibleIds($this->actor($login), $project), $label);
        }

        foreach ($this->listCases($spec, 'cross_lists') as $case) {
            $login = $this->login($case);
            $this->assertSame(
                $this->intList($case, 'ids'),
                $news->visibleIds($this->actor($login), null),
                'cross '.($login ?? 'guest'),
            );
        }

        app(ProjectService::class)->disableModule($this->project(2), 'news');
        $disabled = $spec['after_news_disabled_on_project_2'];
        $this->assertIsArray($disabled);
        foreach ($disabled as $login => $ids) {
            $this->assertIsString($login);
            $this->assertIsArray($ids);
            $actor = $login === 'guest' ? null : $this->actor($login);
            $actual = [];
            foreach ($news->visibleIds($actor, null) as $id) {
                $actual[] = $id;
            }
            $expectedIds = [];
            foreach ($ids as $id) {
                $this->assertIsInt($id);
                $expectedIds[] = $id;
            }
            $this->assertSame($expectedIds, $actual, 'disabled '.$login);
        }
        $this->assertDenied(
            fn () => $news->visibleIds($this->actor('dora'), $this->project(2)),
            'view_news',
            'dora project 2 news disabled',
        );

        app(ProjectService::class)->enableModule($this->project(2), 'news');
        $core = $this->project(1);
        $core->status = Project::STATUS_ARCHIVED;
        $core->save();
        $archived = $spec['after_project_1_archived'];
        $this->assertIsArray($archived);
        foreach ($archived as $login => $ids) {
            $this->assertIsString($login);
            $this->assertIsArray($ids);
            $actor = $login === 'guest' ? null : $this->actor($login);
            $expectedIds = [];
            foreach ($ids as $id) {
                $this->assertIsInt($id);
                $expectedIds[] = $id;
            }
            $this->assertSame($expectedIds, $news->visibleIds($actor, null), 'archived '.$login);
        }
    }

    public function test_news_comments_watchers_and_create_match_the_pin(): void
    {
        $this->boot();
        $this->bootWrites(app(NewsService::class));
    }

    public function test_document_grouping_matches_the_pin(): void
    {
        $expected = $this->boot();
        $documents = app(DocumentService::class);
        $spec = $expected['documents'];
        $this->assertIsArray($spec);
        $sorts = $spec['sorts'];
        $this->assertIsArray($sorts);
        $project = $this->project(1);

        foreach ($sorts as $sort => $groups) {
            $this->assertIsString($sort);
            $this->assertIsArray($groups);
            $this->assertSame($groups, $documents->grouped($this->actor('ada'), $project, $sort), $sort);
        }
        $this->assertSame(
            $documents->grouped($this->actor('ada'), $project, 'category'),
            $documents->grouped($this->actor('ada'), $project, 'nope'),
        );
        $this->assertDenied(
            fn () => $documents->grouped($this->actor('dora'), $this->project(2), 'category'),
            'view_documents',
            'dora documents',
        );
        $child = $spec['project_2_admin'];
        $this->assertIsArray($child);
        $this->assertSame($child, $documents->grouped($this->actor('admin'), $this->project(2), 'category'));

        $ada = $this->actor('ada');
        $this->assertNotNull($ada);
        $this->assertDenied(
            fn () => $documents->delete($ada, $this->document(2)),
            'delete_documents',
            'ada delete',
        );
        $bea = $this->actor('bea');
        $this->assertNotNull($bea);
        try {
            $documents->create($bea, $project, 'Stale', $this->intField($spec, 'inactive_category_id'), null);
            $this->fail('Inactive category was accepted.');
        } catch (DomainException $exception) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $exception);
            $this->assertSame('Category is not active.', $exception->getMessage());
        }
        $documents->delete($bea, $this->document(1));
        $this->assertNull(Document::query()->find(1));
    }

    public function test_file_version_groups_and_downloads_match_the_pin(): void
    {
        $expected = $this->boot();
        $files = app(ProjectFileService::class);
        $spec = $expected['files'];
        $this->assertIsArray($spec);
        $project = $this->project(1);
        $sorts = $spec['sorts'];
        $this->assertIsArray($sorts);

        foreach ($sorts as $sort => $containers) {
            $this->assertIsString($sort);
            $this->assertIsArray($containers);
            $this->assertSame($containers, $files->grouped($this->actor('bea'), $project, $sort), $sort);
        }

        $flat = [];
        foreach ($files->grouped($this->actor('bea'), $project, 'filename') as $container) {
            foreach ($container['attachment_ids'] as $id) {
                $flat[] = $id;
            }
        }
        $this->assertNotContains(1, $flat);
        $this->assertNotContains(2, $flat);

        $child = $spec['project_2'];
        $this->assertIsArray($child);
        $this->assertSame($child, $files->grouped($this->actor('dora'), $this->project(2), 'filename'));
        $this->assertDenied(
            fn () => $files->grouped($this->actor('cleo'), $this->project(2), 'filename'),
            'view_files',
            'cleo files',
        );

        $downloaded = $files->recordDownload($this->actor('ada'), $project, $this->attachment(5));
        $this->assertSame(2, (int) $downloaded->downloads);
        $ada = $this->actor('ada');
        $this->assertNotNull($ada);
        $this->assertDenied(
            fn () => $files->attach($ada, $project, null, 'nope.txt', 'nope'),
            'manage_files',
            'ada upload',
        );
        $bea = $this->actor('bea');
        $this->assertNotNull($bea);
        $created = $files->attach($bea, $project, null, 'added.txt', 'added');
        $this->assertSame('Project', $created->container_type);
        $this->assertSame(1, (int) $created->container_id);
        $this->assertSame(4, (int) $created->author_id);
        $this->assertSame(0, (int) $created->downloads);

        try {
            $files->recordDownload($bea, $project, $this->attachment(1));
            $this->fail('Issue attachment was counted as a project file.');
        } catch (DomainException $exception) {
            $this->assertSame('File is not attached to this project.', $exception->getMessage());
        }

        $this->assertDenied(
            fn () => $files->delete($ada, $project, $this->attachment(6)),
            'manage_files',
            'ada delete file',
        );
        $files->delete($bea, $project, $this->attachment(7));
        $this->assertNull(Attachment::query()->find(7));
    }

    public function test_checklist_keeps_wiki_boards_and_calendar_unverified(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| News, documents, and files \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/ModulesNewsDocumentsFilesParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/modules/news-documents-files.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Boards and forums \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Calendar and Gantt \| NOT VERIFIED \|/m', $checklist);
    }

    /**
     * @return array<string, mixed>
     */
    private function boot(): array
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $this->assertSame('7.0.1', $expected['pin'] ?? null);
        $this->assertSame(4, (int) DB::table('enumerations')->max('id'));
        $this->assertSame(2, (int) DB::table('attachments')->max('id'));
        $this->assertSame(0, DB::table('news')->count());
        $this->assertSame(0, DB::table('documents')->count());
        $this->applySetup($expected);
        $this->insertTable('enumerations', $expected);
        $this->insertTable('news', $expected);
        $this->insertTable('documents', $expected);
        $this->insertTable('comments', $expected);
        $this->insertTable('attachments', $expected);
        $dates = $expected['version_dates'] ?? null;
        $this->assertIsArray($dates);
        foreach ($dates as $id => $date) {
            $this->assertIsString($date);
            Version::query()->where('id', (int) $id)->update(['effective_date' => $date]);
        }

        return $expected;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function applySetup(array $expected): void
    {
        $setup = $expected['setup'];
        $this->assertIsArray($setup);
        $modules = $setup['modules'] ?? null;
        $this->assertIsArray($modules);
        $projects = app(ProjectService::class);
        foreach ($modules as $module) {
            $this->assertIsArray($module);
            $projects->enableModule(
                $this->project($this->intField($module, 'project_id')),
                $this->stringField($module, 'name'),
            );
        }

        $grants = $setup['grants'] ?? null;
        $this->assertIsArray($grants);
        foreach ($grants as $grant) {
            $this->assertIsArray($grant);
            $role = Role::query()->find($this->intField($grant, 'role_id'));
            $this->assertInstanceOf(Role::class, $role);
            $names = $role->permissions;
            $this->assertIsArray($names);
            $add = $grant['permissions'] ?? null;
            $this->assertIsArray($add);
            foreach ($add as $permission) {
                $this->assertIsString($permission);
                $names[] = $permission;
            }
            $role->permissions = array_values(array_unique($names));
            $role->save();
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function insertTable(string $table, array $expected): void
    {
        $rows = $expected['rows'][$table] ?? null;
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            DB::table($table)->insert($row);
        }
    }

    private function bootWrites(NewsService $news): void
    {
        $cleo = $this->actor('cleo');
        $ada = $this->actor('ada');
        $bea = $this->actor('bea');
        $lars = $this->actor('lars');
        $this->assertNotNull($cleo);
        $this->assertNotNull($ada);
        $this->assertNotNull($bea);
        $this->assertNotNull($lars);
        $item = $this->news(1);

        $added = $news->addComment($cleo, $item, 'Noted');
        $item->refresh();
        $this->assertSame('Noted', (string) $added->content);
        $this->assertSame(5, (int) $added->author_id);
        $this->assertSame('News', $added->commented_type);
        $this->assertSame(2, (int) $item->comments_count);

        $this->assertDenied(fn () => $news->addComment($lars, $item, 'No'), 'comment_news', 'lars comment');
        $this->assertDenied(
            fn () => $news->deleteComment($ada, $item, $this->comment(1)),
            'manage_news',
            'ada delete comment',
        );
        $news->deleteComment($bea, $item, $added);
        $item->refresh();
        $this->assertSame(1, (int) $item->comments_count);
        $this->assertNull(Comment::query()->find($added->id));

        $news->watch($ada, $item);
        $news->watch($ada, $item);
        $news->watch($cleo, $item);
        $this->assertSame([1, 5], $news->watcherIds($ada, $item));
        $news->unwatch($ada, $item);
        $this->assertSame([5], $news->watcherIds($cleo, $item));
        $this->assertSame(1, Watcher::query()->where('watchable_type', 'News')->where('watchable_id', (int) $item->id)->count());
        $this->assertDenied(fn () => $news->watch($lars, $item), 'view_news', 'lars watch');
        $this->assertDenied(fn () => $news->watch($cleo, $this->news(3)), 'view_news', 'cleo watch child');

        $this->assertDenied(fn () => $news->create($ada, $this->project(1), 'Nope', null, null), 'manage_news', 'ada create');
        $created = $news->create($bea, $this->project(1), 'Managed', 'Sum', 'Body');
        $this->assertSame(4, (int) $created->author_id);
        $this->assertSame(0, (int) $created->comments_count);
        $this->assertSame(1, (int) $created->project_id);
        $this->assertSame([], $news->watcherIds($bea, $created));
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/modules/news-documents-files.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<array<string, mixed>>
     */
    private function cases(array $expected, string $key): array
    {
        $cases = $expected[$key] ?? null;
        $this->assertIsArray($cases);
        $rows = [];
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $rows[] = $case;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function listCases(array $spec, string $key): array
    {
        $cases = $spec[$key] ?? null;
        $this->assertIsArray($cases);
        $rows = [];
        foreach ($cases as $case) {
            $this->assertIsArray($case);
            $rows[] = $case;
        }

        return $rows;
    }

    /**
     * @param  callable(): mixed  $call
     */
    private function assertDenied(callable $call, string $permission, string $label): void
    {
        try {
            $call();
            $this->fail($label.' was allowed.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame($permission, $exception->permission, $label);
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function login(array $case): ?string
    {
        $login = $case['login'] ?? null;
        if ($login === null) {
            return null;
        }
        $this->assertIsString($login);

        return $login;
    }

    private function actor(?string $login): ?User
    {
        if ($login === null) {
            return null;
        }
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function project(int $id): Project
    {
        $project = Project::query()->find($id);
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function news(int $id): News
    {
        $news = News::query()->find($id);
        $this->assertInstanceOf(News::class, $news);

        return $news;
    }

    private function document(int $id): Document
    {
        $document = Document::query()->find($id);
        $this->assertInstanceOf(Document::class, $document);

        return $document;
    }

    private function attachment(int $id): Attachment
    {
        $attachment = Attachment::query()->find($id);
        $this->assertInstanceOf(Attachment::class, $attachment);

        return $attachment;
    }

    private function comment(int $id): Comment
    {
        $comment = Comment::query()->find($id);
        $this->assertInstanceOf(Comment::class, $comment);

        return $comment;
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
     * @param  array<string, mixed>  $row
     */
    private function boolField(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        $this->assertIsBool($value, $key);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<int>
     */
    private function intList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value, $key);
        $ids = [];
        foreach ($value as $id) {
            $this->assertIsInt($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
