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
