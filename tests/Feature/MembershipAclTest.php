<?php

namespace Tests\Feature;

use App\Domain\Acl\BuiltinRole;
use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\Issues\IssueService;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DefaultAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class MembershipAclTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_allows_and_denies_by_permission_name(): void
    {
        $world = DomainFixture::boot();
        $permissions = app(PermissionService::class);
        $stranger = User::factory()->create();

        $this->assertFalse($permissions->allowed($stranger, 'add_issues', $world->project));

        $world->join();
        $this->assertTrue($permissions->allowed($world->user, 'add_issues', $world->project));
        $this->assertFalse($permissions->allowed($world->user, 'delete_issues', $world->project));
        $this->assertTrue(Gate::forUser($world->user)->allows('view_issues', $world->project));
        $this->assertFalse(Gate::forUser($stranger)->allows('add_issues', $world->project));
        $this->assertTrue($world->user->can('view', $world->project));
        $this->assertFalse($stranger->can('update', $world->project));

        try {
            app(IssueService::class)->create($stranger, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Blocked',
            ]);
            $this->fail('A user without add_issues should not create an issue.');
        } catch (PermissionDeniedException $exception) {
            $this->assertSame('add_issues', $exception->permission);
        }
    }

    public function test_disabled_module_denies_modular_permission(): void
    {
        $world = DomainFixture::boot();
        $world->join();
        $projects = app(ProjectService::class);
        $permissions = app(PermissionService::class);

        $projects->disableModule($world->project, 'issue_tracking');
        $this->assertFalse($permissions->allowed($world->user, 'view_issues', $world->project->fresh()));
        $this->assertTrue($permissions->allowed($world->user, 'view_project', $world->project->fresh()));

        $projects->enableModule($world->project, 'time_tracking');
        $world->role->permissions = ['view_time_entries'];
        $world->role->save();
        $this->assertTrue($permissions->allowed($world->user->fresh(), 'view_time_entries', $world->project->fresh()));
        $projects->disableModule($world->project, 'time_tracking');
        $this->assertFalse($permissions->allowed($world->user->fresh(), 'view_time_entries', $world->project->fresh()));
    }

    public function test_builtin_roles_and_requirement_flags(): void
    {
        $world = DomainFixture::boot();
        Role::query()->create([
            'name' => 'Non member',
            'builtin' => BuiltinRole::NON_MEMBER,
            'assignable' => false,
            'permissions' => ['view_issues', 'edit_project'],
            'issues_visibility' => 'default',
        ]);
        Role::query()->create([
            'name' => 'Anonymous',
            'builtin' => BuiltinRole::ANONYMOUS,
            'assignable' => false,
            'permissions' => ['add_issues', 'edit_project', 'add_project'],
            'issues_visibility' => 'default',
        ]);

        $visitor = User::factory()->create();
        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->allowed($visitor, 'view_issues', $world->project));
        $this->assertFalse($permissions->allowed($visitor, 'edit_project', $world->project));
        $this->assertFalse($permissions->allowed(null, 'add_project', null));
        $this->assertTrue($permissions->allowed(null, 'add_issues', $world->project));
        $this->assertFalse($permissions->allowed(null, 'edit_project', $world->project));

        $world->project->is_public = false;
        $world->project->save();
        $this->assertFalse($permissions->allowed($visitor, 'view_issues', $world->project->fresh()));
        $this->assertFalse($permissions->allowed(null, 'view_project', $world->project->fresh()));

        $world->project->is_public = true;
        $world->project->save();
        $this->assertTrue($permissions->allowed(null, 'view_project', $world->project->fresh()));
    }

    public function test_private_project_hides_non_members_and_admin_bypasses(): void
    {
        $world = DomainFixture::boot();
        $world->project->is_public = false;
        $world->project->save();
        $project = $world->project->fresh();
        $permissions = app(PermissionService::class);
        $admin = User::factory()->create(['admin' => true]);

        $this->assertFalse($permissions->allowed($world->user, 'view_project', $project));
        $this->assertTrue($permissions->allowed($admin, 'delete_issues', $project));

        $locked = User::factory()->create(['admin' => true, 'status' => 3]);
        $this->assertFalse($permissions->allowed($locked, 'view_project', $project));
    }

    public function test_group_membership_and_inherit_members_cascade(): void
    {
        $world = DomainFixture::boot();
        $projects = app(ProjectService::class);
        $memberships = app(MembershipService::class);
        $child = $projects->create([
            'name' => 'Child',
            'identifier' => 'child',
            'inherit_members' => true,
        ], $world->project);
        $projects->enableModule($child, 'issue_tracking');
        $grandchild = $projects->create([
            'name' => 'Grandchild',
            'identifier' => 'grandchild',
            'inherit_members' => true,
        ], $child);
        $projects->enableModule($grandchild, 'issue_tracking');

        $group = User::factory()->create([
            'type' => User::TYPE_GROUP,
            'firstname' => '',
            'lastname' => 'Developers',
        ]);
        $member = User::factory()->create();
        $memberships->addUserToGroup($group, $member);
        $origin = $memberships->assignRole($world->project, $group, $world->role);
        $late = User::factory()->create();
        $memberships->addUserToGroup($group, $late);

        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->allowed($member, 'add_issues', $child->fresh()));
        $this->assertTrue($permissions->allowed($member, 'add_issues', $grandchild->fresh()));
        $this->assertTrue($permissions->allowed($late, 'add_issues', $grandchild->fresh()));
        $memberships->removeUserFromGroup($group, $late);
        $this->assertFalse($permissions->allowed($late->fresh(), 'add_issues', $grandchild->fresh()));
        $this->assertTrue(
            $child->members()->where('user_id', $group->id)->exists()
        );

        $memberships->revokeRole($origin->fresh());
        $this->assertFalse($permissions->allowed($member->fresh(), 'add_issues', $child->fresh()));
        $this->assertFalse($permissions->allowed($member->fresh(), 'add_issues', $grandchild->fresh()));
        $this->assertSame(0, $world->project->members()->count());
    }

    public function test_yaml_permission_text_is_readable_and_json_is_stored(): void
    {
        $role = Role::query()->create([
            'name' => 'Imported',
            'builtin' => 0,
            'permissions' => "---\n- :view_issues\n- :add_issues\n",
        ]);
        $this->assertTrue($role->fresh()->grants('view_issues'));
        $this->assertFalse($role->fresh()->grants('edit_issues'));

        $role->permissions = ['edit_issues'];
        $role->save();
        $stored = $role->fresh()?->getRawOriginal('permissions');
        $this->assertIsString($stored);
        $this->assertStringStartsWith('[', $stored);
        $this->assertTrue($role->fresh()->grants('edit_issues'));
    }

    public function test_issues_visibility_all_default_and_own(): void
    {
        $world = DomainFixture::boot();
        $world->join();
        $author = User::factory()->create();
        $authorRole = Role::query()->create([
            'name' => 'Author',
            'builtin' => 0,
            'permissions' => ['view_issues', 'add_issues', 'edit_issues', 'manage_subtasks', 'set_issues_private'],
            'issues_visibility' => 'all',
        ]);
        app(MembershipService::class)->assignRole($world->project, $author, $authorRole);

        $issues = app(IssueService::class);
        $mine = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Mine',
            'status_id' => $world->newStatus->id,
        ]);
        $private = $issues->create($author, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Secret',
            'status_id' => $world->newStatus->id,
            'is_private' => true,
        ]);

        $visibility = app(IssueVisibility::class);
        $this->assertTrue($visibility->canSee($world->user, $mine));
        $this->assertFalse($visibility->canSee($world->user, $private));

        $world->role->issues_visibility = 'all';
        $world->role->save();
        $this->assertTrue($visibility->canSee($world->user->fresh(), $private->fresh()));

        $world->role->issues_visibility = 'own';
        $world->role->save();
        $openByOther = $issues->create($author->fresh(), $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Other open',
            'status_id' => $world->newStatus->id,
        ]);
        $this->assertTrue($visibility->canSee($world->user->fresh(), $mine->fresh()));
        $this->assertFalse($visibility->canSee($world->user->fresh(), $openByOther));
        $this->assertSame(IssueVisibility::OWN, $visibility->effective(
            app(PermissionService::class)->rolesFor($world->user->fresh(), $world->project)
        ));
    }

    public function test_default_access_seeder_writes_builtin_roles(): void
    {
        $this->seed(DefaultAccessSeeder::class);

        $nonMember = Role::query()->where('builtin', BuiltinRole::NON_MEMBER)->first();
        $anonymous = Role::query()->where('builtin', BuiltinRole::ANONYMOUS)->first();
        $manager = Role::query()->where('name', 'Manager')->first();

        $this->assertNotNull($nonMember);
        $this->assertNotNull($anonymous);
        $this->assertNotNull($manager);
        $this->assertSame([], $nonMember->permissions);
        $this->assertFalse($anonymous->assignable);
        $this->assertTrue($manager->grants('edit_issues'));
        $this->assertTrue($manager->grants('log_time'));
        $this->assertFalse($manager->grants('view_wiki_pages'));
        $stored = $manager->getRawOriginal('permissions');
        $this->assertIsString($stored);
        $this->assertStringStartsWith('[', $stored);
    }
}
