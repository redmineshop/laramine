<?php

namespace Tests\Parity;

use App\Domain\Auth\RedminePassword;
use App\Models\CustomField;
use App\Models\Query;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Proves the shared pin loads on MySQL 8.
 *
 * This is a harness self-test. It does not compare Laramine behavior to
 * Redmine and it does not mark a checklist row VERIFIED.
 */
class Redmine701FixtureHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pinned_fixture_loads_on_mysql_8(): void
    {
        $loaded = Redmine701Fixture::load();

        $this->assertSame(Redmine701Fixture::PIN, $loaded->pin);
        $this->assertSame(Redmine701Fixture::ORIGIN, $loaded->origin);
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $version = DB::scalar('select version()');
        $this->assertIsString($version);
        $this->assertMatchesRegularExpression('/^8\./', $version);

        foreach ($loaded->counts as $table => $count) {
            $this->assertGreaterThan(0, $count, $table);
            $this->assertSame($count, DB::table($table)->count(), $table);
        }

        foreach (Redmine701Fixture::SCM_TABLES as $table) {
            $this->assertArrayNotHasKey($table, $loaded->counts);
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        }

        $this->assertSame(7, $loaded->nextId('issues'));
    }

    public function test_loaded_rows_match_the_pin_shape_without_verifying_a_domain(): void
    {
        Redmine701Fixture::load();

        $parent = DB::table('projects')->where('identifier', 'parity-core')->first();
        $child = DB::table('projects')->where('identifier', 'parity-child')->first();
        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertSame(1, (int) $child->parent_id);
        $this->assertLessThan((int) $child->lft, (int) $parent->lft);
        $this->assertLessThan((int) $child->rgt, (int) $child->lft);
        $this->assertLessThan((int) $parent->rgt, (int) $child->rgt);

        $root = DB::table('issues')->where('id', 1)->first();
        $subtask = DB::table('issues')->where('id', 2)->first();
        $this->assertNotNull($root);
        $this->assertNotNull($subtask);
        $this->assertNull($root->parent_id);
        $this->assertSame(1, (int) $subtask->parent_id);
        $this->assertSame(1, (int) $root->root_id);
        $this->assertSame(1, (int) $subtask->root_id);
        $this->assertSame(1, (int) $root->project_id);
        $this->assertSame(1, (int) $root->tracker_id);

        $this->assertSame(1, DB::table('members')->where('user_id', 1)->where('project_id', 1)->count());
        $this->assertSame(5, DB::table('workflows')->where('type', 'WorkflowTransition')->count());
        $this->assertSame(1, DB::table('workflows')->where('type', 'WorkflowPermission')->where('rule', 'required')->count());
        $this->assertSame(1, DB::table('time_entries')->where('tweek', 35)->count());

        $developer = Role::query()->find(1);
        $manager = Role::query()->find(2);
        $this->assertInstanceOf(Role::class, $developer);
        $this->assertInstanceOf(Role::class, $manager);
        $this->assertTrue($developer->grants('view_issues'));
        $this->assertFalse($developer->grants('delete_issues'));
        $this->assertTrue($manager->grants('delete_issues'));

        $query = Query::query()->find(1);
        $this->assertInstanceOf(Query::class, $query);
        $this->assertSame('IssueQuery', $query->type);
        $this->assertIsArray($query->filters);
        $this->assertSame('o', $query->filters['status_id']['operator']);
        $this->assertSame(['subject', 'status', 'tracker'], $query->column_names);
        $this->assertSame([['id', 'desc']], $query->sort_criteria);

        $list = CustomField::query()->find(2);
        $link = CustomField::query()->find(4);
        $this->assertInstanceOf(CustomField::class, $list);
        $this->assertInstanceOf(CustomField::class, $link);
        $this->assertSame(['alpha', 'beta'], $list->possibleValueList());
        $store = $link->formatStoreData();
        $this->assertIsArray($store);
        $this->assertSame('https://example.test/%value%', $store['url_pattern']);
        $this->assertSame('Issue', DB::table('custom_values')->where('id', 1)->value('customized_type'));

        $passwords = new RedminePassword;
        $ada = DB::table('users')->where('id', 1)->first();
        $admin = DB::table('users')->where('id', 2)->first();
        $this->assertNotNull($ada);
        $this->assertNotNull($admin);
        $this->assertTrue($passwords->verify('secret', $ada->salt, (string) $ada->hashed_password));
        $this->assertFalse($passwords->verify('secret', $admin->salt, (string) $admin->hashed_password));

        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/', $checklist);
        $this->assertDoesNotMatchRegularExpression(
            '/^\| (?!P0 table and column layout \|)(?!Users and authentication)(?!Identity, membership, and permissions \|)(?!Projects and issue nested sets \|)(?!Workflows \|)(?!Custom fields \|)(?!Queries \|)(?!Journals and private notes \|)(?!Time entries and attachments \|)(?!Activity \|)(?!News, documents, and files \|)[^|\n]+\| VERIFIED \|/m',
            $checklist,
        );
    }
}
