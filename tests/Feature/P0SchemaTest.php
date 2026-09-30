<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Import;
use App\Models\ImportItem;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\TimeEntry;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the P0 migrations apply on MySQL 8 and that core rows can be stored.
 * This is a schema smoke test. It does not verify Redmine behavior.
 */
class P0SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrate_creates_p0_tables_and_keeps_later_layers_out(): void
    {
        $p0Tables = [
            'users',
            'email_addresses',
            'tokens',
            'user_preferences',
            'auth_sources',
            'roles',
            'members',
            'member_roles',
            'groups_users',
            'roles_managed_roles',
            'enabled_modules',
            'oauth_applications',
            'oauth_access_grants',
            'oauth_access_tokens',
            'projects',
            'projects_trackers',
            'versions',
            'issue_categories',
            'enumerations',
            'trackers',
            'issue_statuses',
            'issues',
            'issue_relations',
            'watchers',
            'journals',
            'journal_details',
            'workflows',
            'reactions',
            'custom_fields',
            'custom_fields_trackers',
            'custom_fields_projects',
            'custom_fields_roles',
            'custom_values',
            'custom_field_enumerations',
            'time_entries',
            'attachments',
            'comments',
            'queries',
            'queries_roles',
            'imports',
            'import_items',
        ];

        foreach ($p0Tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        foreach (['wikis', 'wiki_pages', 'repositories', 'changesets', 'boards', 'messages', 'news', 'documents', 'webhooks', 'projects_webhooks'] as $skipped) {
            $this->assertFalse(Schema::hasTable($skipped), $skipped);
        }

        $this->assertTrue(Schema::hasColumns('settings', ['name', 'value', 'updated_on']));

        $this->assertTrue(Schema::hasColumns('users', [
            'login',
            'hashed_password',
            'salt',
            'firstname',
            'lastname',
            'admin',
            'status',
            'type',
            'auth_source_id',
            'must_change_passwd',
            'twofa_scheme',
        ]));
        $this->assertFalse(Schema::hasColumn('users', 'email'));
        $this->assertFalse(Schema::hasColumn('users', 'password'));

        $this->assertTrue(Schema::hasColumns('projects', ['parent_id', 'lft', 'rgt', 'identifier']));
        $this->assertFalse(Schema::hasColumn('projects', 'root_id'));
        $this->assertTrue(Schema::hasColumns('issues', ['parent_id', 'root_id', 'lft', 'rgt', 'lock_version']));
        $this->assertTrue(Schema::hasColumns('trackers', ['private_by_default', 'fields_bits']));
        $this->assertTrue(Schema::hasColumns('attachments', ['container_type', 'container_id']));
        $this->assertTrue(Schema::hasColumns('custom_values', ['customized_type', 'customized_id']));
        $this->assertTrue(Schema::hasColumns('journals', ['journalized_type', 'journalized_id']));
        $this->assertTrue(Schema::hasColumns('watchers', ['watchable_type', 'watchable_id']));
        $this->assertTrue(Schema::hasColumns('comments', ['commented_type', 'commented_id']));
        $this->assertTrue(Schema::hasColumns('reactions', ['reactable_type', 'reactable_id']));
        $this->assertTrue(Schema::hasColumns('custom_fields', ['type', 'field_format', 'format_store']));
        $this->assertTrue(Schema::hasColumns('workflows', ['type', 'field_name', 'rule']));
        $this->assertTrue(Schema::hasColumn('queries', 'filters'));

        $userIndexNames = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($userIndexNames->contains('index_users_on_lower_login'));
        $this->assertTrue($userIndexNames->contains('index_users_on_login'));

        $issueForeignColumns = collect(Schema::getForeignKeys('issues'))
            ->pluck('columns')
            ->flatten();
        $this->assertTrue($issueForeignColumns->contains('project_id'));
        $this->assertTrue($issueForeignColumns->contains('parent_id'));
        $this->assertTrue($issueForeignColumns->contains('root_id'));

        $memberForeignColumns = collect(Schema::getForeignKeys('members'))
            ->pluck('columns')
            ->flatten();
        $this->assertFalse($memberForeignColumns->contains('user_id'));
        $this->assertFalse($memberForeignColumns->contains('project_id'));

        $grantForeignTables = collect(Schema::getForeignKeys('oauth_access_grants'))
            ->pluck('foreign_table');
        $this->assertTrue($grantForeignTables->contains('users'));
        $this->assertTrue($grantForeignTables->contains('oauth_applications'));

        $this->assertSame('mysql', Schema::getConnection()->getDriverName());

        $firstname = collect(Schema::getColumns('users'))->firstWhere('name', 'firstname');
        $this->assertIsArray($firstname);
        $this->assertSame('varchar(30)', $firstname['type']);

        $loginIndex = DB::selectOne(
            'select EXPRESSION as expression from information_schema.STATISTICS where TABLE_SCHEMA = database() and TABLE_NAME = ? and INDEX_NAME = ?',
            ['users', 'index_users_on_lower_login'],
        );
        $this->assertNotNull($loginIndex);
        $this->assertIsString($loginIndex->expression);
        $this->assertStringContainsString('lower', strtolower($loginIndex->expression));
        $this->assertStringContainsString('login', strtolower($loginIndex->expression));
    }

    public function test_core_rows_round_trip_with_nested_set_columns(): void
    {
        $user = User::factory()->create();
        $priority = Enumeration::query()->create([
            'name' => 'Normal',
            'type' => 'IssuePriority',
            'active' => true,
            'is_default' => true,
        ]);
        $activity = Enumeration::query()->create([
            'name' => 'Development',
            'type' => 'TimeEntryActivity',
        ]);
        $status = IssueStatus::query()->create([
            'name' => 'New',
            'is_closed' => false,
        ]);
        $tracker = Tracker::query()->create([
            'name' => 'Bug',
            'default_status_id' => $status->id,
            'private_by_default' => false,
        ]);
        $project = Project::query()->create([
            'name' => 'Demo',
            'identifier' => 'demo',
            'lft' => 1,
            'rgt' => 4,
        ]);
        $child = Project::query()->create([
            'name' => 'Child',
            'identifier' => 'demo-child',
            'parent_id' => $project->id,
            'lft' => 2,
            'rgt' => 3,
        ]);
        $issue = Issue::query()->create([
            'project_id' => $project->id,
            'tracker_id' => $tracker->id,
            'status_id' => $status->id,
            'priority_id' => $priority->id,
            'author_id' => $user->id,
            'subject' => 'Schema smoke',
            'lft' => 1,
            'rgt' => 2,
        ]);
        $issue->root_id = $issue->id;
        $issue->save();

        $field = CustomField::query()->create([
            'name' => 'Severity',
            'field_format' => 'string',
            'type' => 'IssueCustomField',
        ]);
        CustomValue::query()->create([
            'custom_field_id' => $field->id,
            'customized_type' => 'Issue',
            'customized_id' => $issue->id,
            'value' => 'high',
        ]);

        $query = Query::query()->create([
            'name' => 'Open bugs',
            'user_id' => $user->id,
            'project_id' => $project->id,
            'type' => 'IssueQuery',
            'filters' => "--- {}\n",
        ]);
        $project->default_issue_query_id = $query->id;
        $project->save();

        $role = Role::query()->create([
            'name' => 'Manager',
            'permissions' => "---\n- :view_issues\n",
        ]);
        $member = Member::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
        ]);
        MemberRole::query()->create([
            'member_id' => $member->id,
            'role_id' => $role->id,
        ]);
        Workflow::query()->create([
            'tracker_id' => $tracker->id,
            'role_id' => $role->id,
            'old_status_id' => $status->id,
            'new_status_id' => $status->id,
            'type' => 'WorkflowTransition',
        ]);
        TimeEntry::query()->create([
            'project_id' => $project->id,
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'hours' => 1.5,
            'spent_on' => '2026-08-26',
            'tyear' => 2026,
            'tmonth' => 8,
            'tweek' => 35,
        ]);
        $import = Import::query()->create([
            'user_id' => $user->id,
            'type' => 'IssueImport',
            'filename' => 'issues.csv',
        ]);
        ImportItem::query()->create([
            'import_id' => $import->id,
            'position' => 1,
        ]);

        $this->assertSame(2, $child->lft);
        $this->assertTrue($project->children->contains(fn (Project $row): bool => $row->id === $child->id));
        $this->assertSame($issue->id, $issue->fresh()?->root_id);
        $this->assertTrue($user->authoredIssues->contains(fn (Issue $row): bool => $row->id === $issue->id));
        $this->assertSame('Issue', CustomValue::query()->first()?->customized_type);
        $this->assertSame($query->id, $project->fresh()?->default_issue_query_id);
        $this->assertSame(1, Workflow::query()->count());
        $this->assertSame(1, TimeEntry::query()->count());
        $this->assertNotNull($import->items()->first());
    }
}
