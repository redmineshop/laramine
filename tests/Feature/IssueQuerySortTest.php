<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\DomainException;
use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Queries\SavedQueryService;
use App\Models\CustomField;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class IssueQuerySortTest extends TestCase
{
    use RefreshDatabase;

    public function test_sorts_priority_status_and_tracker_by_position(): void
    {
        $world = $this->member();
        $world->priority->position = 4;
        $world->priority->save();
        $urgent = Enumeration::query()->create([
            'name' => 'Urgent',
            'type' => 'IssuePriority',
            'active' => true,
            'position' => 1,
        ]);
        $world->newStatus->position = 9;
        $world->newStatus->save();
        $triage = IssueStatus::query()->create([
            'name' => 'Triage',
            'is_closed' => false,
            'position' => 1,
        ]);
        $world->tracker->position = 5;
        $world->tracker->save();
        $epic = Tracker::query()->create([
            'name' => 'Epic',
            'default_status_id' => $world->newStatus->id,
            'position' => 1,
        ]);
        app(ProjectService::class)->attachTracker($world->project, $epic);

        $urgentIssue = $this->issue($world, ['priority_id' => $urgent->id, 'subject' => 'Urgent']);
        $normalIssue = $this->issue($world, ['subject' => 'Normal']);
        $triageIssue = $this->issue($world, ['status_id' => $triage->id, 'subject' => 'Triage']);
        $newIssue = $this->issue($world, ['subject' => 'New']);
        $bugIssue = $this->issue($world, ['subject' => 'Bug']);
        $epicIssue = $this->issue($world, ['tracker_id' => $epic->id, 'subject' => 'Epic']);

        $runner = app(IssueQueryRunner::class);
        $this->assertSame(
            [$urgentIssue->id, $normalIssue->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Urgent', 'Normal']), [['priority', 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$normalIssue->id, $urgentIssue->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Urgent', 'Normal']), [['priority_id', 'desc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$triageIssue->id, $newIssue->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Triage', 'New']), [['status', 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$epicIssue->id, $bugIssue->id],
            $this->ids($runner->preview(
                $world->user,
                $world->project,
                $this->subjects(['Epic', 'Bug']),
                [],
                'tracker',
            )),
        );
    }

    public function test_sorts_author_and_assignee_by_firstname_then_lastname(): void
    {
        $world = $this->member();
        $zoe = User::factory()->create(['firstname' => 'Zoe', 'lastname' => 'Young']);
        $annAye = User::factory()->create(['firstname' => 'Ann', 'lastname' => 'Aye']);
        $annZee = User::factory()->create(['firstname' => 'Ann', 'lastname' => 'Zee']);
        $ops = User::factory()->create([
            'firstname' => '',
            'lastname' => 'Ops',
            'type' => User::TYPE_GROUP,
            'login' => '',
        ]);

        $zoeIssue = $this->issue($world, ['author_id' => $zoe->id, 'subject' => 'Zoe']);
        $ayeIssue = $this->issue($world, ['author_id' => $annAye->id, 'subject' => 'Aye']);
        $zeeIssue = $this->issue($world, ['author_id' => $annZee->id, 'subject' => 'Zee']);
        $open = $this->issue($world, ['assigned_to_id' => null, 'subject' => 'Open']);
        $group = $this->issue($world, ['assigned_to_id' => $ops->id, 'subject' => 'Group']);
        $named = $this->issue($world, ['assigned_to_id' => $annAye->id, 'subject' => 'Named']);

        $runner = app(IssueQueryRunner::class);
        $authors = ['subject' => ['operator' => '=', 'values' => ['Zoe', 'Aye', 'Zee']]];
        $this->assertSame(
            [$ayeIssue->id, $zeeIssue->id, $zoeIssue->id],
            $this->ids($runner->preview($world->user, $world->project, $authors, [['author', 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$zeeIssue->id, $ayeIssue->id],
            $this->ids($runner->preview($world->user, $world->project, [
                'subject' => ['operator' => '=', 'values' => ['Aye', 'Zee']],
            ], [['author_id', 'desc'], ['id', 'asc']])),
        );

        $assignees = ['subject' => ['operator' => '=', 'values' => ['Open', 'Group', 'Named']]];
        $this->assertSame(
            [$open->id, $group->id, $named->id],
            $this->ids($runner->preview($world->user, $world->project, $assignees, [['assigned_to', 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$named->id, $group->id, $open->id],
            $this->ids($runner->preview($world->user, $world->project, $assignees, [['assigned_to_id', 'desc'], ['id', 'asc']])),
        );
    }

    public function test_sorts_custom_fields_by_a_single_minimum_value(): void
    {
        $world = $this->member();
        $label = $this->field('string', 'Label');
        $points = $this->field('int', 'Points');
        $cost = $this->field('float', 'Cost');
        $ratio = $this->field('progressbar', 'Ratio');
        $when = $this->field('date', 'When');
        $rank = $this->field('enumeration', 'Rank');
        $owner = $this->field('user', 'Owner');
        $target = $this->field('version', 'Target');

        $early = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $rank->id,
            'name' => 'Early',
            'active' => true,
            'position' => 9,
        ]);
        $late = CustomFieldEnumeration::query()->create([
            'custom_field_id' => $rank->id,
            'name' => 'Late',
            'active' => true,
            'position' => 1,
        ]);
        $zoe = User::factory()->create(['firstname' => 'Zoe', 'lastname' => 'Young']);
        $ann = User::factory()->create(['firstname' => 'Ann', 'lastname' => 'Bee']);
        $zebra = Version::query()->create([
            'name' => 'Zebra',
            'project_id' => $world->project->id,
            'status' => 'open',
            'sharing' => 'none',
        ]);
        $alpha = Version::query()->create([
            'name' => 'Alpha',
            'project_id' => $world->project->id,
            'status' => 'open',
            'sharing' => 'none',
        ]);

        $blank = $this->issue($world, ['subject' => 'Blank']);
        $bee = $this->issue($world, ['subject' => 'Bee']);
        $ant = $this->issue($world, ['subject' => 'Ant']);
        $this->value($label, $bee, 'z');
        $this->value($label, $bee, 'a');
        $this->value($label, $ant, 'b');

        $two = $this->issue($world, ['subject' => 'Two']);
        $ten = $this->issue($world, ['subject' => 'Ten']);
        $junk = $this->issue($world, ['subject' => 'Junk']);
        $this->value($points, $two, '2');
        $this->value($points, $ten, '10');
        $this->value($points, $junk, '10abc');

        $small = $this->issue($world, ['subject' => 'Small']);
        $big = $this->issue($world, ['subject' => 'Big']);
        $this->value($cost, $small, '9.5');
        $this->value($cost, $big, '10');

        $lowBar = $this->issue($world, ['subject' => 'LowBar']);
        $highBar = $this->issue($world, ['subject' => 'HighBar']);
        $this->value($ratio, $lowBar, '2');
        $this->value($ratio, $highBar, '10');

        $december = $this->issue($world, ['subject' => 'December']);
        $january = $this->issue($world, ['subject' => 'January']);
        $this->value($when, $december, '2020-12-01');
        $this->value($when, $january, '2020-01-02');

        $earlyIssue = $this->issue($world, ['subject' => 'Early']);
        $lateIssue = $this->issue($world, ['subject' => 'Late']);
        $this->value($rank, $earlyIssue, (string) $early->id);
        $this->value($rank, $lateIssue, (string) $late->id);

        $zoeOnly = $this->issue($world, ['subject' => 'ZoeOnly']);
        $both = $this->issue($world, ['subject' => 'Both']);
        $this->value($owner, $zoeOnly, (string) $zoe->id);
        $this->value($owner, $both, (string) $zoe->id);
        $this->value($owner, $both, (string) $ann->id);

        $zebraIssue = $this->issue($world, ['subject' => 'Zebra']);
        $alphaIssue = $this->issue($world, ['subject' => 'Alpha']);
        $this->value($target, $zebraIssue, (string) $zebra->id);
        $this->value($target, $alphaIssue, (string) $alpha->id);

        $runner = app(IssueQueryRunner::class);
        $this->assertSame(
            [$blank->id, $bee->id, $ant->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Blank', 'Ant', 'Bee']), [['cf_'.$label->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$junk->id, $two->id, $ten->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Junk', 'Two', 'Ten']), [['cf_'.$points->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$ten->id, $two->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Two', 'Ten']), [['cf_'.$points->id, 'desc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$small->id, $big->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Small', 'Big']), [['cf_'.$cost->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$lowBar->id, $highBar->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['LowBar', 'HighBar']), [['cf_'.$ratio->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$january->id, $december->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['December', 'January']), [['cf_'.$when->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$lateIssue->id, $earlyIssue->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Early', 'Late']), [['cf_'.$rank->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$both->id, $zoeOnly->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['ZoeOnly', 'Both']), [['cf_'.$owner->id, 'asc'], ['id', 'asc']])),
        );
        $this->assertSame(
            [$alphaIssue->id, $zebraIssue->id],
            $this->ids($runner->preview($world->user, $world->project, $this->subjects(['Zebra', 'Alpha']), [['cf_'.$target->id, 'asc'], ['id', 'asc']])),
        );

        $this->value($label, $ten, 'a');
        $this->value($label, $two, 'b');
        $saved = app(SavedQueryService::class)->create($world->user, [
            'name' => 'Points',
            'project_id' => $world->project->id,
            'filters' => $this->subjects(['Two', 'Ten']),
            'sort_criteria' => [['cf_'.$points->id, 'asc']],
            'group_by' => 'cf_'.$label->id,
        ]);
        $this->assertSame(
            [$ten->id, $two->id],
            $this->ids($runner->execute($world->user, $saved)),
        );
    }

    public function test_rejects_custom_field_sorts_that_cannot_run(): void
    {
        $world = $this->member();
        $hidden = $this->field('string', 'Secret');
        $hidden->visible = false;
        $hidden->save();
        $attachment = $this->field('attachment', 'File');
        $broken = $this->field('string', 'Broken');
        $broken->field_format = 'nope';
        $broken->save();
        $projectField = CustomField::query()->create([
            'name' => 'Budget',
            'field_format' => 'int',
            'type' => 'ProjectCustomField',
            'is_for_all' => true,
            'visible' => true,
        ]);
        $visible = $this->field('string', 'Shown');
        $issue = $this->issue($world, ['subject' => 'One']);
        $this->value($hidden, $issue, 'x');

        $runner = app(IssueQueryRunner::class);
        $saved = app(SavedQueryService::class);
        $this->assertSortRejected($world, 'cf_'.$hidden->id, 'Custom field is not visible: cf_'.$hidden->id.'.');
        $this->assertSortRejected($world, 'cf_'.$attachment->id, 'Custom field is not sortable: cf_'.$attachment->id.'.');
        $this->assertSortRejected($world, 'cf_'.$broken->id, 'Custom field is not sortable: cf_'.$broken->id.'.');
        $this->assertSortRejected($world, 'cf_'.$projectField->id, 'Custom field sort is unknown: cf_'.$projectField->id.'.');
        $this->assertSortRejected($world, 'cf_999999', 'Custom field sort is unknown: cf_999999.');
        $this->assertSortRejected($world, 'watches', 'Sort column is not available: watches.');
        $this->assertSame(0, DB::table('queries')->where('name', 'Bad sort')->count());

        try {
            $runner->preview($world->user, $world->project, [], [], 'cf_'.$hidden->id);
            $this->fail('A hidden group key is an error.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Custom field is not visible: cf_'.$hidden->id.'.', $exception->getMessage());
        }

        try {
            $saved->create($world->user, [
                'name' => 'Stub sort',
                'type' => QueryType::PROJECT,
                'project_id' => $world->project->id,
                'sort_criteria' => [['cf_'.$visible->id, 'asc']],
            ]);
            $this->fail('Stub queries cannot store a custom field sort.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Sort column is not available: cf_'.$visible->id.'.', $exception->getMessage());
        }

        $admin = User::factory()->create(['admin' => true]);
        $public = $saved->create($admin, [
            'name' => 'Hidden sort',
            'project_id' => $world->project->id,
            'visibility' => 2,
            'sort_criteria' => [['cf_'.$hidden->id, 'asc']],
        ]);
        $this->assertSame([$issue->id], $this->ids($runner->execute($admin, $public)));

        try {
            $runner->execute($world->user, $public);
            $this->fail('A hidden sort must fail for a user who cannot see the field.');
        } catch (QueryValidationException $exception) {
            $this->assertSame('Custom field is not visible: cf_'.$hidden->id.'.', $exception->getMessage());
        }

        $private = $saved->create($world->user, [
            'name' => 'Private sort',
            'project_id' => $world->project->id,
            'visibility' => 0,
            'sort_criteria' => [['id', 'asc']],
        ]);
        $stranger = User::factory()->create();
        app(MembershipService::class)->assignRole($world->project, $stranger, $world->role);

        try {
            $runner->execute($stranger, $private);
            $this->fail('A private query must not run for another user.');
        } catch (DomainException $exception) {
            $this->assertSame('Saved query is not visible.', $exception->getMessage());
        }
    }

    private function assertSortRejected(DomainFixture $world, string $name, string $message): void
    {
        try {
            app(SavedQueryService::class)->create($world->user, [
                'name' => 'Bad sort',
                'project_id' => $world->project->id,
                'sort_criteria' => [[$name, 'asc']],
            ]);
            $this->fail($message);
        } catch (QueryValidationException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function field(string $format, string $name): CustomField
    {
        return CustomField::query()->create([
            'name' => $name,
            'field_format' => $format,
            'type' => 'IssueCustomField',
            'is_filter' => false,
            'is_for_all' => true,
            'visible' => true,
        ]);
    }

    private function value(CustomField $field, Issue $issue, string $value): void
    {
        CustomValue::query()->create([
            'custom_field_id' => $field->id,
            'customized_type' => 'Issue',
            'customized_id' => $issue->id,
            'value' => $value,
        ]);
    }

    /**
     * @param  list<string>  $subjects
     * @return array<string, array{operator: string, values: list<string>}>
     */
    private function subjects(array $subjects): array
    {
        return ['subject' => ['operator' => '=', 'values' => $subjects]];
    }

    private function member(): DomainFixture
    {
        $world = DomainFixture::boot('query-sort');
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

    /**
     * @param  Builder<Issue>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        $ids = [];
        foreach ($query->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
