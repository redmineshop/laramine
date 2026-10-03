<?php

namespace Tests\Unit;

use App\Domain\Acl\PermissionCatalog;
use App\Domain\Acl\PermissionList;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_catalog_has_eighty_names_across_eleven_modules(): void
    {
        $catalog = new PermissionCatalog;

        $this->assertCount(80, $catalog->names());
        $this->assertCount(80, array_unique($catalog->names()));
        $this->assertCount(15, $catalog->namesForModule('project'));
        $this->assertCount(20, $catalog->namesForModule('issue_tracking'));
        $this->assertCount(7, $catalog->namesForModule('time_tracking'));
        $this->assertCount(10, PermissionCatalog::ENABLED_MODULES);
        $this->assertFalse($catalog->isEnabledModule('project'));
        $this->assertTrue($catalog->isEnabledModule('issue_tracking'));

        $viewProject = $catalog->definition('view_project');
        $this->assertTrue($viewProject->public);
        $this->assertTrue($viewProject->read);
        $this->assertNull($viewProject->require);

        $editProject = $catalog->definition('edit_project');
        $this->assertFalse($editProject->public);
        $this->assertSame('member', $editProject->require);

        $addProject = $catalog->definition('add_project');
        $this->assertSame('loggedin', $addProject->require);

        $closeProject = $catalog->definition('close_project');
        $this->assertTrue($closeProject->read);
        $this->assertSame('member', $closeProject->require);
    }

    public function test_mvp_issue_time_and_query_permission_flags(): void
    {
        $catalog = new PermissionCatalog;

        $modular = [
            'view_issues' => [false, false, null, 'issue_tracking'],
            'add_issues' => [false, false, null, 'issue_tracking'],
            'edit_issues' => [false, false, null, 'issue_tracking'],
            'edit_own_issues' => [false, false, null, 'issue_tracking'],
            'manage_subtasks' => [false, false, null, 'issue_tracking'],
            'set_issues_private' => [false, false, null, 'issue_tracking'],
            'add_issue_notes' => [false, false, null, 'issue_tracking'],
            'set_own_issues_private' => [false, false, 'loggedin', 'issue_tracking'],
            'delete_issues' => [false, false, 'member', 'issue_tracking'],
            'view_private_notes' => [false, true, 'member', 'issue_tracking'],
            'set_notes_private' => [false, false, 'member', 'issue_tracking'],
            'view_time_entries' => [false, true, null, 'time_tracking'],
            'log_time' => [false, false, 'loggedin', 'time_tracking'],
            'edit_time_entries' => [false, false, null, 'time_tracking'],
            'edit_own_time_entries' => [false, false, null, 'time_tracking'],
            'log_time_for_other_users' => [false, false, 'member', 'time_tracking'],
        ];

        foreach ($modular as $name => [$public, $read, $require, $module]) {
            $definition = $catalog->definition($name);
            $this->assertSame($module, $definition->module, $name);
            $this->assertSame($public, $definition->public, $name);
            $this->assertSame($read, $definition->read, $name);
            $this->assertSame($require, $definition->require, $name);
            $this->assertTrue($definition->isModular(), $name);
        }

        $save = $catalog->definition('save_queries');
        $this->assertSame('project', $save->module);
        $this->assertFalse($save->isModular());
        $this->assertSame('loggedin', $save->require);
        $this->assertFalse($save->public);

        $manage = $catalog->definition('manage_public_queries');
        $this->assertSame('project', $manage->module);
        $this->assertFalse($manage->isModular());
        $this->assertSame('member', $manage->require);
        $this->assertFalse($manage->public);
    }

    public function test_permission_list_json_round_trip_and_yaml_symbols(): void
    {
        $encoded = PermissionList::encode(['view_issues', 'add_issues', 'view_issues']);
        $this->assertSame('["view_issues","add_issues"]', $encoded);
        $this->assertSame(['view_issues', 'add_issues'], PermissionList::decode($encoded));
        $this->assertSame(
            ['view_issues', 'add_issues'],
            PermissionList::decode("---\n- :view_issues\n- :add_issues\n"),
        );
        $this->assertSame([], PermissionList::decode(null));
        $this->assertSame([], PermissionList::decode(''));
        $this->assertSame([], PermissionList::decode('not-a-list'));
    }
}
