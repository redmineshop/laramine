<?php

namespace Tests\Feature;

use App\Domain\Acl\BuiltinRole;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\UserVisibility;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Domain\Projects\ProjectService;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * `roles.users_visibility` allow and deny.
 *
 * This is Laramine behavior on MySQL. The pin comparison is
 * tests/Parity/IdentityAclParityTest.php.
 */
class UserVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_mode_hides_outsiders_and_inactive_users(): void
    {
        $world = $this->world();
        $visibility = app(UserVisibility::class);
        $outsider = User::factory()->create();
        $locked = User::factory()->create(['status' => User::STATUS_LOCKED]);
        app(MembershipService::class)->assignRole($world->project, $locked, $world->role);
        $admin = User::factory()->create(['admin' => true]);

        $this->assertTrue($visibility->canSee($world->user, $world->user));
        $this->assertFalse($visibility->canSee($world->user, $outsider));
        $this->assertFalse($visibility->canSee($world->user, $locked));
        $this->assertTrue($visibility->canSee($admin, $locked));
        $this->assertTrue($visibility->canSee($admin, $outsider));
    }

    public function test_all_is_the_most_open_role_and_still_hides_inactive_users(): void
    {
        $world = $this->world();
        $visibility = app(UserVisibility::class);
        $outsider = User::factory()->create();
        $locked = User::factory()->create(['status' => User::STATUS_LOCKED]);
        $open = Role::query()->create([
            'name' => 'Directory',
            'builtin' => BuiltinRole::CUSTOM,
            'permissions' => ['view_issues'],
            'users_visibility' => UserVisibility::ALL,
        ]);
        app(MembershipService::class)->assignRole($world->project, $world->user, $open);

        $this->assertTrue($visibility->canSee($world->user->fresh(), $outsider));
        $this->assertFalse($visibility->canSee($world->user->fresh(), $locked));
    }

    public function test_guest_uses_anonymous_and_a_non_member_sees_self(): void
    {
        $world = $this->world();
        $world->project->is_public = false;
        $world->project->save();
        $public = app(ProjectService::class)->create([
            'name' => 'Public',
            'identifier' => 'public-vis',
            'is_public' => true,
        ]);
        $publicMember = User::factory()->create();
        app(MembershipService::class)->assignRole($public, $publicMember, $world->role);

        Role::query()->create([
            'name' => 'Non member',
            'builtin' => BuiltinRole::NON_MEMBER,
            'permissions' => [],
            'users_visibility' => UserVisibility::MEMBERS_OF_VISIBLE_PROJECTS,
        ]);
        Role::query()->create([
            'name' => 'Anonymous',
            'builtin' => BuiltinRole::ANONYMOUS,
            'permissions' => [],
            'users_visibility' => UserVisibility::MEMBERS_OF_VISIBLE_PROJECTS,
        ]);

        $visibility = app(UserVisibility::class);
        $stranger = User::factory()->create();

        $this->assertFalse($visibility->canSee(null, $world->user->fresh()));
        $this->assertTrue($visibility->canSee(null, $publicMember));
        $this->assertTrue($visibility->canSee($stranger, $stranger));
        $this->assertTrue($visibility->canSee($stranger, $publicMember));
        $this->assertFalse($visibility->canSee($stranger, $world->user->fresh()));
    }

    public function test_new_assignee_must_be_visible(): void
    {
        $world = $this->world();
        $issues = app(IssueService::class);
        $outsider = User::factory()->create();

        try {
            $issues->create($world->user, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'Hidden assignee',
                'assigned_to_id' => $outsider->id,
            ]);
            $this->fail('A hidden user cannot be assigned.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not visible', $exception->getMessage());
            $this->assertSame(0, $world->project->issues()->count());
        }

        $world->role->users_visibility = UserVisibility::ALL;
        $world->role->save();
        $created = $issues->create($world->user->fresh(), $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Visible assignee',
            'assigned_to_id' => $outsider->id,
        ]);
        $this->assertSame($outsider->id, $created->assigned_to_id);
    }

    private function world(): DomainFixture
    {
        $world = DomainFixture::boot('user-vis');
        $world->join();
        $world->role->users_visibility = UserVisibility::MEMBERS_OF_VISIBLE_PROJECTS;
        $world->role->save();

        return $world;
    }
}
