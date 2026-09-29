<?php

namespace Tests\Unit;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\Formats\UserFormat;
use App\Domain\CustomFields\Formats\VersionFormat;
use App\Domain\Projects\ProjectService;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class CustomFieldRecordFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_format_requires_a_project_member_and_optional_role(): void
    {
        $world = DomainFixture::boot('user-cf');
        $world->join();
        $format = app(UserFormat::class);
        $issue = new Issue(['project_id' => $world->project->id]);
        $issue->setRelation('project', $world->project);

        $open = new CustomField(['field_format' => 'user', 'multiple' => true]);
        $this->assertSame([], $format->validate($open, (string) $world->user->id, $issue));
        $this->assertSame([(string) $world->user->id], $format->serialize($open, $world->user->id));
        $this->assertSame($world->user->id, $format->cast($open, (string) $world->user->id));

        $outsider = User::factory()->create();
        $this->assertSame(
            ['User is not a member of the project.'],
            $format->validate($open, $outsider->id, $issue),
        );
        $this->assertSame(['Value must be a user id.'], $format->validate($open, 'abc', $issue));
        $this->assertSame(['User does not exist.'], $format->validate($open, 999999, $issue));

        $otherRole = Role::query()->create([
            'name' => 'Manager',
            'builtin' => 0,
            'permissions' => ['view_issues'],
        ]);
        $limited = new CustomField([
            'field_format' => 'user',
            'format_store' => ['user_role' => [$otherRole->id]],
        ]);
        $this->assertSame(
            ['User does not have an allowed role.'],
            $format->validate($limited, $world->user->id, $issue),
        );

        $allowed = new CustomField([
            'field_format' => 'user',
            'format_store' => ['user_role' => [$world->role->id]],
        ]);
        $this->assertSame([], $format->validate($allowed, [(string) $world->user->id], $issue));
        $this->assertTrue($format->supportsMultiple());
        $this->assertFalse($format->supportsSearchable());
        $this->assertSame('list_optional', $format->queryFilterType());

        $inactive = User::factory()->create(['status' => 3]);
        app(MembershipService::class)->assignRole($world->project, $inactive, $world->role);
        $this->assertSame(['User is not active.'], $format->validate($open, $inactive->id, $issue));
    }

    public function test_version_format_is_limited_to_the_project_and_status(): void
    {
        $world = DomainFixture::boot('version-cf');
        $format = app(VersionFormat::class);
        $issue = new Issue(['project_id' => $world->project->id]);
        $issue->setRelation('project', $world->project);

        $open = Version::query()->create([
            'project_id' => $world->project->id,
            'name' => '1.0',
            'status' => 'open',
        ]);
        $locked = Version::query()->create([
            'project_id' => $world->project->id,
            'name' => '0.9',
            'status' => 'locked',
        ]);
        $otherProject = app(ProjectService::class)->create([
            'name' => 'Other',
            'identifier' => 'other-ver',
        ]);
        $foreign = Version::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'ext',
            'status' => 'open',
        ]);

        $field = new CustomField([
            'field_format' => 'version',
            'multiple' => true,
            'format_store' => ['version_status' => ['open']],
        ]);
        $this->assertSame([], $format->validate($field, (string) $open->id, $issue));
        $this->assertSame([(string) $open->id], $format->serialize($field, $open->id));
        $this->assertSame($open->id, $format->cast($field, (string) $open->id));
        $this->assertSame(
            ['Version status is not allowed.'],
            $format->validate($field, $locked->id, $issue),
        );
        $this->assertSame(
            ['Version is not available for this project.'],
            $format->validate($field, $foreign->id, $issue),
        );
        $this->assertSame(['Value must be a version id.'], $format->validate($field, 'nope', $issue));
        $this->assertSame(['version_status must be open, locked, or closed.'], $format->validateDefinition(new CustomField([
            'format_store' => ['version_status' => ['archived']],
        ])));
        $this->assertTrue($format->supportsMultiple());
        $this->assertSame('list_optional', $format->queryFilterType());
    }
}
