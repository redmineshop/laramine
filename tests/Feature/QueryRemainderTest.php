<?php

namespace Tests\Feature;

use App\Domain\DomainException;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\ProjectQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryVisibility;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\Query;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * Remaining-hours arithmetic and a hidden project custom field.
 *
 * The pin comparison is tests/Parity/QueryRemainderParityTest.php.
 */
class QueryRemainderTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_hours_use_the_undone_ratio(): void
    {
        $world = $this->member();
        $half = $this->issue($world, ['estimated_hours' => 5.5, 'done_ratio' => 50, 'subject' => 'Half']);
        $done = $this->issue($world, ['estimated_hours' => 1.1, 'done_ratio' => 100, 'subject' => 'Done']);
        $blank = $this->issue($world, ['estimated_hours' => null, 'done_ratio' => 0, 'subject' => 'Blank']);

        $query = new Query;
        $query->type = QueryType::ISSUE;
        $query->name = 'Remaining';
        $query->user_id = $world->user->id;
        $query->project_id = $world->project->id;
        $query->visibility = QueryVisibility::PUBLIC;
        $query->filters = ['status_id' => ['operator' => '*', 'values' => []]];
        $query->column_names = ['estimated_remaining_hours'];
        $query->sort_criteria = [['estimated_remaining_hours', 'desc'], ['id', 'asc']];
        $query->options = [
            'display_type' => 'list',
            'totalable_names' => ['estimated_remaining_hours'],
        ];

        $runner = app(IssueQueryRunner::class);
        $view = $runner->present($world->user, $query);
        $cells = [];
        foreach ($view->rows as $row) {
            $cells[$row->issueId] = $row->values['estimated_remaining_hours'] ?? null;
        }

        $this->assertSame('2.75', $cells[$half->id]);
        $this->assertSame('0', $cells[$done->id]);
        $this->assertSame('0', $cells[$blank->id]);
        $this->assertSame([$half->id, $done->id, $blank->id], array_map(
            static fn ($row): int => $row->issueId,
            $view->rows,
        ));
        $this->assertSame(['estimated_remaining_hours' => '2.75'], $runner->totals($world->user, $query));
    }

    public function test_hidden_project_field_is_rejected_for_a_role_outside_the_list(): void
    {
        $world = $this->member();
        $manager = Role::query()->create([
            'name' => 'Manager',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues'],
            'issues_visibility' => 'default',
        ]);
        $secret = CustomField::query()->create([
            'name' => 'Secret',
            'field_format' => 'string',
            'type' => 'ProjectCustomField',
            'is_filter' => true,
            'is_for_all' => true,
            'visible' => false,
        ]);
        $secret->roles()->attach($manager->id);
        CustomValue::query()->create([
            'custom_field_id' => $secret->id,
            'customized_type' => 'Project',
            'customized_id' => $world->project->id,
            'value' => 'hidden',
        ]);

        $runner = app(ProjectQueryRunner::class);
        $filters = ['cf_'.$secret->id => ['operator' => '=', 'values' => ['hidden']]];
        try {
            $runner->run($world->user, QueryType::PROJECT, $filters);
            $this->fail('A role outside custom_fields_roles cannot filter the field.');
        } catch (DomainException $exception) {
            $this->assertSame('Custom field is not visible: cf_'.$secret->id.'.', $exception->getMessage());
        }

        $admin = User::factory()->create(['admin' => true]);
        $result = $runner->run($admin, QueryType::PROJECT, $filters, ['name', 'cf_'.$secret->id]);
        $this->assertSame([$world->project->id], $result['ids']);
        $this->assertSame('hidden', $result['rows'][0]['cf_'.$secret->id]);
    }

    private function member(): DomainFixture
    {
        $world = DomainFixture::boot('query-remainder');
        $world->role->permissions = [
            'view_issues',
            'add_issues',
            'edit_issues',
            'save_queries',
        ];
        $world->role->save();
        $world->join();

        return $world;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issue(DomainFixture $world, array $overrides): Issue
    {
        return Issue::query()->create([
            'project_id' => $world->project->id,
            'tracker_id' => $world->tracker->id,
            'status_id' => $world->newStatus->id,
            'priority_id' => $world->priority->id,
            'author_id' => $world->user->id,
            'subject' => 'Subject',
            'done_ratio' => 0,
            'is_private' => false,
            'lock_version' => 0,
            ...$overrides,
        ]);
    }
}
