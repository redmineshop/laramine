<?php

namespace Tests\Unit;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\Formats\AttachmentFormat;
use App\Domain\CustomFields\Formats\EnumerationFormat;
use App\Domain\CustomFields\Formats\UserFormat;
use App\Domain\CustomFields\Formats\VersionFormat;
use App\Domain\Projects\ProjectService;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
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

    public function test_version_format_follows_sharing(): void
    {
        $world = DomainFixture::boot('version-share');
        $projects = app(ProjectService::class);
        $child = $projects->create([
            'name' => 'Child',
            'identifier' => 'ver-child',
        ], $world->project);
        $other = $projects->create([
            'name' => 'Other',
            'identifier' => 'ver-other',
        ]);
        $format = app(VersionFormat::class);
        $field = new CustomField([
            'field_format' => 'version',
            'format_store' => ['version_status' => ['open']],
        ]);
        $down = $this->version($world->project->id, 'down', 'open', 'descendants');
        $up = $this->version($child->id, 'up', 'open', 'hierarchy');
        $childOnly = $this->version($child->id, 'local', 'open', 'descendants');
        $tree = $this->version($world->project->id, 'tree', 'open', 'tree');
        $system = $this->version($other->id, 'system', 'open', 'system');
        $locked = $this->version($world->project->id, 'locked', 'locked', 'system');
        $weird = $this->version($other->id, 'weird', 'open', 'custom');

        $onChild = new Issue(['project_id' => $child->id]);
        $onChild->setRelation('project', $child->fresh() ?? $child);
        $onParent = new Issue(['project_id' => $world->project->id]);
        $onParent->setRelation('project', $world->project->fresh() ?? $world->project);
        $onOther = new Issue(['project_id' => $other->id]);
        $onOther->setRelation('project', $other);

        $this->assertSame([], $format->validate($field, $down->id, $onChild));
        $this->assertSame([], $format->validate($field, $up->id, $onParent));
        $this->assertSame(
            ['Version is not available for this project.'],
            $format->validate($field, $childOnly->id, $onParent),
        );
        $this->assertSame([], $format->validate($field, $tree->id, $onChild));
        $this->assertSame(
            ['Version is not available for this project.'],
            $format->validate($field, $tree->id, $onOther),
        );
        $this->assertSame([], $format->validate($field, $system->id, $onParent));
        $this->assertSame(
            ['Version status is not allowed.'],
            $format->validate($field, $locked->id, $onOther),
        );
        $this->assertSame(
            ['Version is not available for this project.'],
            $format->validate($field, $weird->id, $onParent),
        );
    }

    public function test_enumeration_format_stores_active_ids_for_this_field(): void
    {
        $format = new EnumerationFormat;
        $field = CustomField::query()->create([
            'name' => 'Kind',
            'field_format' => 'enumeration',
            'type' => 'IssueCustomField',
            'multiple' => true,
            'position' => 1,
        ]);
        $other = CustomField::query()->create([
            'name' => 'Other',
            'field_format' => 'enumeration',
            'type' => 'IssueCustomField',
            'multiple' => false,
            'position' => 2,
        ]);
        $alpha = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $field->id,
            'name' => 'Alpha',
            'active' => true,
            'position' => 1,
        ]);
        $beta = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $field->id,
            'name' => 'Beta',
            'active' => true,
            'position' => 2,
        ]);
        $retired = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $field->id,
            'name' => 'Retired',
            'active' => false,
            'position' => 3,
        ]);
        $foreign = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $other->id,
            'name' => 'Foreign',
            'active' => true,
            'position' => 1,
        ]);

        $this->assertSame([], $format->validate($field, [(string) $beta->id, (string) $alpha->id], null));
        $this->assertSame([(string) $beta->id, (string) $alpha->id], $format->serialize($field, [$beta->id, $alpha->id]));
        $this->assertSame($alpha->id, $format->cast($field, (string) $alpha->id));
        $this->assertSame(['Enumeration is not active.'], $format->validate($field, $retired->id, null));
        $this->assertSame(['Enumeration does not exist.'], $format->validate($field, $foreign->id, null));
        $this->assertSame(['Enumeration does not exist.'], $format->validate($field, 999999, null));
        $this->assertSame(['Value must be an enumeration id.'], $format->validate($field, 'abc', null));
        $this->assertSame(['Value is repeated.'], $format->validate($field, [$alpha->id, $alpha->id], null));

        $single = CustomField::query()->create([
            'name' => 'One',
            'field_format' => 'enumeration',
            'type' => 'IssueCustomField',
            'multiple' => false,
            'position' => 3,
        ]);
        $only = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $single->id,
            'name' => 'Only',
            'active' => true,
            'position' => 1,
        ]);
        $this->assertSame(
            ['Multiple values are not supported for this format.'],
            $format->validate($single, [(string) $only->id, (string) $alpha->id], null),
        );
        $single->default_value = (string) $only->id;
        $this->assertSame([], $format->validateDefinition($single));
        $unsaved = new CustomField([
            'field_format' => 'enumeration',
            'default_value' => '4',
        ]);
        $this->assertSame([], $format->validateDefinition($unsaved));
        $single->default_value = 'nope';
        $this->assertSame(['Default value must be an enumeration id.'], $format->validateDefinition($single));
        $this->assertTrue($format->supportsMultiple());
        $this->assertFalse($format->supportsSearchable());
        $this->assertFalse($format->supportsTotal());
        $this->assertSame('list_optional', $format->queryFilterType());
    }

    public function test_attachment_format_stores_an_id_and_checks_extension(): void
    {
        $world = DomainFixture::boot('attachment-cf');
        $format = app(AttachmentFormat::class);
        $field = new CustomField([
            'field_format' => 'attachment',
            'format_store' => ['extensions_allowed' => 'pdf, png'],
        ]);
        $bound = Attachment::query()->create([
            'container_type' => 'Issue',
            'container_id' => 40,
            'filename' => 'spec.PDF',
            'disk_filename' => 'spec.PDF',
            'filesize' => 12,
            'author_id' => $world->user->id,
        ]);
        $unbound = Attachment::query()->create([
            'container_type' => null,
            'container_id' => null,
            'filename' => 'notes.png',
            'disk_filename' => 'notes.png',
            'filesize' => 4,
            'author_id' => $world->user->id,
        ]);
        $text = Attachment::query()->create([
            'container_type' => 'Issue',
            'container_id' => 40,
            'filename' => 'notes.txt',
            'disk_filename' => 'notes.txt',
            'filesize' => 4,
            'author_id' => $world->user->id,
        ]);
        $issue = new Issue;
        $issue->id = 40;

        $this->assertSame([], $format->validate($field, (string) $bound->id, $issue));
        $this->assertSame([(string) $bound->id], $format->serialize($field, $bound->id));
        $this->assertSame($bound->id, $format->cast($field, (string) $bound->id));
        $this->assertSame([], $format->validate($field, (string) $unbound->id, $issue));
        $this->assertSame(['Attachment extension is not allowed.'], $format->validate($field, $text->id, $issue));
        $this->assertSame(['Attachment does not exist.'], $format->validate($field, 999999, $issue));
        $this->assertSame(['Value must be an attachment id.'], $format->validate($field, 'file.pdf', $issue));
        $other = new Issue;
        $other->id = 41;
        $this->assertSame(
            ['Attachment is not attached to this record.'],
            $format->validate($field, $bound->id, $other),
        );
        $this->assertSame(
            ['Multiple values are not supported for this format.'],
            $format->validate($field, [(string) $bound->id, (string) $unbound->id], $issue),
        );
        $this->assertSame(['extensions_allowed must be a list of extensions.'], $format->validateDefinition(new CustomField([
            'format_store' => ['extensions_allowed' => ['pdf', 1]],
        ])));
        $this->assertFalse($format->supportsMultiple());
        $this->assertFalse($format->supportsSearchable());
        $this->assertFalse($format->supportsTotal());
        $this->assertSame('string', $format->queryFilterType());
    }

    private function version(int $projectId, string $name, string $status, string $sharing): Version
    {
        return Version::query()->create([
            'project_id' => $projectId,
            'name' => $name,
            'status' => $status,
            'sharing' => $sharing,
        ]);
    }
}
