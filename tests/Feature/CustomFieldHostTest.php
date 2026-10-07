<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\CustomFields\CustomValueService;
use App\Domain\CustomFields\UserFieldOptions;
use App\Domain\Projects\ProjectService;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class CustomFieldHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_options_omit_non_members_even_when_visibility_is_all(): void
    {
        $world = DomainFixture::boot('cf-user-options');
        $world->join();
        $outsider = User::factory()->create();
        $field = app(CustomFieldService::class)->save([
            'type' => 'IssueCustomField',
            'name' => 'Watcher',
            'field_format' => 'user',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);
        $options = app(UserFieldOptions::class);

        $offered = $options->offered($world->user, $field, $world->project);
        $this->assertContains($world->user->id, $offered);
        $this->assertNotContains($outsider->id, $offered);

        $world->role->users_visibility = 'all';
        $world->role->save();
        $open = $options->offered($world->user->fresh(), $field, $world->project);
        $this->assertNotContains($outsider->id, $open);
        $filter = $options->filterList($world->user->fresh(), $world->project);
        $this->assertNotContains($outsider->id, $filter['ids']);
        $this->assertTrue($filter['me']);
        $this->assertFalse($options->filterList(null, $world->project)['me']);
    }

    public function test_enumeration_and_document_hosts_enforce_required_and_permission(): void
    {
        $world = DomainFixture::boot('cf-hosts');
        $world->join();
        $admin = User::factory()->create(['admin' => true]);
        $fields = app(CustomFieldService::class);
        $values = app(CustomValueService::class);
        $note = $fields->save([
            'type' => 'IssuePriorityCustomField',
            'name' => 'Priority note',
            'field_format' => 'string',
            'is_required' => true,
            'default_value' => 'normal',
        ]);
        $priority = Enumeration::query()->create([
            'name' => 'Low',
            'type' => 'IssuePriority',
            'active' => true,
            'position' => 2,
        ]);

        $values->sync($admin, $priority, [], true);
        $this->assertSame('normal', CustomValue::query()->where('custom_field_id', $note->id)->value('value'));
        $this->assertSame('IssuePriority', CustomValue::query()->where('custom_field_id', $note->id)->value('customized_type'));

        try {
            $values->sync($admin, $priority->fresh() ?? $priority, [$note->id => ''], false);
            $this->fail('A required priority field cannot be cleared.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('required', $exception->getMessage());
        }
        $this->assertSame('normal', CustomValue::query()->where('custom_field_id', $note->id)->value('value'));

        $this->actingAs($world->user)
            ->putJson('/enumerations/'.$priority->id.'/custom-fields', [
                'custom_fields' => [['id' => $note->id, 'value' => 'x']],
            ])
            ->assertForbidden();
        $this->actingAs($admin)
            ->get('/enumerations/'.$priority->id.'/custom-fields')
            ->assertOk()
            ->assertSee('Priority note')
            ->assertSee('normal');

        $label = $fields->save([
            'type' => 'DocumentCustomField',
            'name' => 'Doc label',
            'field_format' => 'string',
            'is_required' => true,
            'default_value' => 'unset',
        ]);
        $this->actingAs($world->user)
            ->getJson('/projects/'.$world->project->id.'/documents/4/custom-fields')
            ->assertForbidden();

        $world->role->permissions = array_values(array_unique([
            ...$world->role->permissions,
            'view_documents',
            'add_documents',
            'edit_documents',
        ]));
        $world->role->save();
        app(ProjectService::class)->enableModule($world->project, 'documents');

        $this->actingAs($world->user)
            ->postJson('/projects/'.$world->project->id.'/documents/4/custom-fields', [
                'custom_fields' => [],
            ])
            ->assertOk()
            ->assertJsonPath('custom_fields.0.value', 'unset');
        $this->assertSame('Document', CustomValue::query()->where('custom_field_id', $label->id)->value('customized_type'));

        $stranger = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $stranger, Role::query()->create([
            'name' => 'Reader',
            'builtin' => 0,
            'permissions' => ['view_documents'],
        ]));
        $this->actingAs($stranger)
            ->putJson('/projects/'.$world->project->id.'/documents/4/custom-fields', [
                'custom_fields' => [['id' => $label->id, 'value' => 'nope']],
            ])
            ->assertForbidden();
        $this->assertSame('unset', CustomValue::query()->where('custom_field_id', $label->id)->value('value'));
    }
}
