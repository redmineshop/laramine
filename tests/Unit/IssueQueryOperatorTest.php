<?php

namespace Tests\Unit;

use App\Domain\Projects\ProjectService;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryValidationException;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Tracker;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class IssueQueryOperatorTest extends TestCase
{
    use RefreshDatabase;

    private DomainFixture $world;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00', 'UTC'));
        $this->world = DomainFixture::boot('query-ops');
        $this->world->join();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_equals_and_not_equals_on_tracker(): void
    {
        $other = Tracker::query()->create([
            'name' => 'Task',
            'default_status_id' => $this->world->newStatus->id,
            'position' => 2,
        ]);
        $bug = $this->issue(['subject' => 'Bug']);
        $task = $this->issue(['tracker_id' => $other->id, 'subject' => 'Task']);

        $this->assertIds(['tracker_id' => $this->clause('=', [(string) $this->world->tracker->id])], [$bug->id]);
        $this->assertIds(['tracker_id' => $this->clause('!', [(string) $this->world->tracker->id])], [$task->id]);
    }

    public function test_status_open_closed_any_and_ignored_values(): void
    {
        $open = $this->issue(['subject' => 'Open']);
        $closed = $this->issue(['status_id' => $this->world->closed->id, 'subject' => 'Closed']);

        $this->assertIds(['status_id' => $this->clause('o', ['999'])], [$open->id]);
        $this->assertIds(['status_id' => $this->clause('c', [])], [$closed->id]);
        $this->assertIds(['status_id' => $this->clause('*', [])], [$open->id, $closed->id]);
        $this->assertIds(
            ['status_id' => $this->clause('=', [(string) $this->world->newStatus->id])],
            [$open->id],
        );
        $this->assertIds(
            ['status_id' => $this->clause('!', [(string) $this->world->newStatus->id])],
            [$closed->id],
        );
    }

    public function test_optional_assignee_none_any_and_not_equals_includes_blank(): void
    {
        $assignee = User::factory()->create();
        $other = User::factory()->create();
        $open = $this->issue(['assigned_to_id' => null, 'subject' => 'Unassigned']);
        $mine = $this->issue(['assigned_to_id' => $assignee->id, 'subject' => 'Mine']);
        $theirs = $this->issue(['assigned_to_id' => $other->id, 'subject' => 'Theirs']);

        $this->assertIds(['assigned_to_id' => $this->clause('!*', [])], [$open->id]);
        $this->assertIds(['assigned_to_id' => $this->clause('*', [])], [$mine->id, $theirs->id]);
        $this->assertIds(
            ['assigned_to_id' => $this->clause('=', [(string) $assignee->id])],
            [$mine->id],
        );
        $this->assertIds(
            ['assigned_to_id' => $this->clause('!', [(string) $assignee->id])],
            [$open->id, $theirs->id],
        );
    }

    public function test_category_and_version_none(): void
    {
        $category = IssueCategory::query()->create([
            'name' => 'Backend',
            'project_id' => $this->world->project->id,
        ]);
        $version = Version::query()->create([
            'name' => '1.0',
            'project_id' => $this->world->project->id,
            'status' => 'open',
        ]);
        $blank = $this->issue(['subject' => 'Blank']);
        $filled = $this->issue([
            'category_id' => $category->id,
            'fixed_version_id' => $version->id,
            'subject' => 'Filled',
        ]);

        $this->assertIds(['category_id' => $this->clause('!*', [])], [$blank->id]);
        $this->assertIds(['fixed_version_id' => $this->clause('=', [(string) $version->id])], [$filled->id]);
    }

    public function test_numeric_comparisons_include_bounds_and_null_negation(): void
    {
        $low = $this->issue(['done_ratio' => 10, 'estimated_hours' => null, 'subject' => 'Low']);
        $mid = $this->issue(['done_ratio' => 50, 'estimated_hours' => 3.5, 'subject' => 'Mid']);
        $high = $this->issue(['done_ratio' => 80, 'estimated_hours' => 8, 'subject' => 'High']);

        $this->assertIds(['done_ratio' => $this->clause('=', ['50'])], [$mid->id]);
        $this->assertIds(['done_ratio' => $this->clause('>=', ['50'])], [$mid->id, $high->id]);
        $this->assertIds(['done_ratio' => $this->clause('<=', ['50'])], [$low->id, $mid->id]);
        $this->assertIds(['done_ratio' => $this->clause('><', ['10', '50'])], [$low->id, $mid->id]);
        $this->assertIds(['estimated_hours' => $this->clause('!*', [])], [$low->id]);
        $this->assertIds(['estimated_hours' => $this->clause('*', [])], [$mid->id, $high->id]);
        $this->assertIds(['estimated_hours' => $this->clause('>=', ['3.5'])], [$mid->id, $high->id]);
        $this->assertIds(['issue_id' => $this->clause('=', [(string) $low->id])], [$low->id]);
    }

    public function test_relative_and_explicit_dates(): void
    {
        $today = $this->issue(['due_date' => '2026-09-29', 'subject' => 'Today']);
        $yesterday = $this->issue(['due_date' => '2026-09-28', 'subject' => 'Yesterday']);
        $tomorrow = $this->issue(['due_date' => '2026-09-30', 'subject' => 'Tomorrow']);
        $lastWeek = $this->issue(['due_date' => '2026-09-22', 'subject' => 'Last week']);
        $thisMonth = $this->issue(['due_date' => '2026-09-02', 'subject' => 'This month']);
        $lastMonth = $this->issue(['due_date' => '2026-08-15', 'subject' => 'Last month']);
        $lastYear = $this->issue(['due_date' => '2025-09-29', 'subject' => 'Last year']);
        $blank = $this->issue(['due_date' => null, 'subject' => 'Blank']);

        $this->assertIds(['due_date' => $this->clause('t', [])], [$today->id]);
        $this->assertIds(['due_date' => $this->clause('ld', [])], [$yesterday->id]);
        $this->assertIds(['due_date' => $this->clause('w', [])], [$today->id, $yesterday->id, $tomorrow->id]);
        $this->assertIds(['due_date' => $this->clause('lw', [])], [$lastWeek->id]);
        $this->assertIds(['due_date' => $this->clause('m', [])], [
            $today->id, $yesterday->id, $tomorrow->id, $lastWeek->id, $thisMonth->id,
        ]);
        $this->assertIds(['due_date' => $this->clause('lm', [])], [$lastMonth->id]);
        $this->assertIds(['due_date' => $this->clause('y', [])], [
            $today->id, $yesterday->id, $tomorrow->id, $lastWeek->id, $thisMonth->id, $lastMonth->id,
        ]);
        $this->assertNotContains($lastYear->id, $this->ids(['due_date' => $this->clause('y', [])]));
        $this->assertIds(['due_date' => $this->clause('=', ['2026-09-29'])], [$today->id]);
        $this->assertIds(['due_date' => $this->clause('>=', ['2026-09-30'])], [$tomorrow->id]);
        $this->assertIds(['due_date' => $this->clause('<=', ['2026-09-28'])], [$yesterday->id, $lastWeek->id, $thisMonth->id, $lastMonth->id, $lastYear->id]);
        $this->assertIds(['due_date' => $this->clause('><', ['2026-09-01', '2026-09-15'])], [$thisMonth->id]);
        $this->assertIds(['due_date' => $this->clause('!*', [])], [$blank->id]);
        $this->assertFalse(in_array($blank->id, $this->ids(['due_date' => $this->clause('*', [])]), true));
    }

    public function test_future_and_past_day_offsets(): void
    {
        $today = $this->issue(['due_date' => '2026-09-29', 'subject' => 'Today']);
        $tomorrow = $this->issue(['due_date' => '2026-09-30', 'subject' => 'Tomorrow']);
        $plusTwo = $this->issue(['due_date' => '2026-10-02', 'subject' => 'Plus two']);
        $nextWeek = $this->issue(['due_date' => '2026-10-06', 'subject' => 'Next week']);
        $nextMonth = $this->issue(['due_date' => '2026-10-15', 'subject' => 'Next month']);
        $withinPast = $this->issue(['due_date' => '2026-09-26', 'subject' => 'Within past']);
        $older = $this->issue(['due_date' => '2026-09-25', 'subject' => 'Older']);
        $twoWeeks = $this->issue(['due_date' => '2026-09-20', 'subject' => 'Two weeks']);

        $this->assertIds(['due_date' => $this->clause('nd', [])], [$tomorrow->id]);
        $this->assertIds(['due_date' => $this->clause('nw', [])], [$nextWeek->id]);
        $this->assertIds(['due_date' => $this->clause('nm', [])], [$plusTwo->id, $nextWeek->id, $nextMonth->id]);
        $this->assertIds(['due_date' => $this->clause('l2w', [])], [$withinPast->id, $older->id, $twoWeeks->id]);
        $this->assertIds(['due_date' => $this->clause('t+', ['3'])], [$plusTwo->id]);
        $this->assertIds(['due_date' => $this->clause('t-', ['3'])], [$withinPast->id]);
        $this->assertIds(['due_date' => $this->clause('<t+', ['2'])], [$today->id, $tomorrow->id, $withinPast->id, $older->id, $twoWeeks->id]);
        $this->assertIds(['due_date' => $this->clause('>t+', ['1'])], [$plusTwo->id, $nextWeek->id, $nextMonth->id]);
        $this->assertIds(['due_date' => $this->clause('><t+', ['3'])], [$tomorrow->id, $plusTwo->id]);
        $this->assertIds(['due_date' => $this->clause('>t-', ['3'])], [$today->id, $withinPast->id]);
        $this->assertIds(['due_date' => $this->clause('<t-', ['3'])], [$older->id, $twoWeeks->id]);
        $this->assertIds(['due_date' => $this->clause('><t-', ['3'])], [$withinPast->id]);
        $this->assertSame([], $this->ids(['due_date' => $this->clause('><t+', ['0'])]));
        $this->assertNotContains($today->id, $this->ids(['due_date' => $this->clause('nd', [])]));
        $this->assertNotContains($tomorrow->id, $this->ids(['due_date' => $this->clause('>t+', ['1'])]));
    }

    public function test_past_datetime_offsets_and_rejected_future_operators(): void
    {
        $recent = $this->issue(['subject' => 'Recent']);
        $recent->created_on = '2026-09-29 08:00:00';
        $recent->save();
        $old = $this->issue(['subject' => 'Old']);
        $old->created_on = '2026-09-20 08:00:00';
        $old->save();

        $this->assertIds(['created_on' => $this->clause('>t-', ['1'])], [$recent->id]);
        $this->assertIds(['created_on' => $this->clause('<t-', ['5'])], [$old->id]);
        $this->assertIds(['created_on' => $this->clause('l2w', [])], [$old->id]);
        $this->expectRejection(['created_on' => $this->clause('nd', [])]);
        $this->expectRejection(['created_on' => $this->clause('t+', ['1'])]);
    }

    public function test_datetime_today_uses_the_calendar_day(): void
    {
        $inside = $this->issue(['subject' => 'Inside']);
        $inside->created_on = '2026-09-29 00:30:00';
        $inside->save();
        $outside = $this->issue(['subject' => 'Outside']);
        $outside->created_on = '2026-09-28 23:00:00';
        $outside->save();

        $this->assertIds(['created_on' => $this->clause('t', [])], [$inside->id]);
        $this->assertIds(['created_on' => $this->clause('=', ['2026-09-28'])], [$outside->id]);
    }

    public function test_user_timezone_moves_today_before_utc_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 03:00:00', 'UTC'));
        UserPreference::query()->create([
            'user_id' => $this->world->user->id,
            'time_zone' => 'America/New_York',
            'hide_mail' => true,
        ]);
        $localToday = $this->issue(['due_date' => '2026-09-29', 'subject' => 'Local today']);
        $utcToday = $this->issue(['due_date' => '2026-09-30', 'subject' => 'UTC today']);

        $this->assertIds(['due_date' => $this->clause('t', [])], [$localToday->id]);
        $this->assertNotContains($utcToday->id, $this->ids(['due_date' => $this->clause('t', [])]));
    }

    public function test_subject_contains_tokens_and_exact_match(): void
    {
        $login = $this->issue(['subject' => 'Fix login']);
        $api = $this->issue(['subject' => 'Fix API']);
        $docs = $this->issue(['subject' => 'Docs', 'description' => '']);
        $percent = $this->issue(['subject' => '100% done']);

        $this->assertIds(['subject' => $this->clause('~', ['Fix'])], [$login->id, $api->id]);
        $this->assertIds(['subject' => $this->clause('~', ['fix login'])], [$login->id]);
        $this->assertIds(['subject' => $this->clause('!~', ['Fix'])], [$docs->id, $percent->id]);
        $this->assertIds(['subject' => $this->clause('=', ['Fix login'])], [$login->id]);
        $this->assertIds(['subject' => $this->clause('~', ['100%'])], [$percent->id]);
        $this->assertIds(['subject' => $this->clause('*~', ['login API'])], [$login->id, $api->id]);
        $this->assertIds(['subject' => $this->clause('^', ['Fix'])], [$login->id, $api->id]);
        $this->assertIds(['subject' => $this->clause('^', ['Fix login'])], [$login->id]);
        $this->assertIds(['subject' => $this->clause('$', ['API'])], [$api->id]);
        $this->assertSame([], $this->ids(['subject' => $this->clause('^', ['login'])]));
        $this->assertIds(['description' => $this->clause('!*', [])], [$login->id, $api->id, $docs->id, $percent->id]);
    }

    public function test_private_flag_parent_and_child(): void
    {
        $parent = $this->issue(['subject' => 'Parent', 'is_private' => true]);
        $child = $this->issue(['subject' => 'Child', 'parent_id' => $parent->id, 'is_private' => false]);
        $alone = $this->issue(['subject' => 'Alone']);

        $this->assertIds(['is_private' => $this->clause('=', ['1'])], [$parent->id]);
        $this->assertIds(['is_private' => $this->clause('!', ['true'])], [$child->id, $alone->id]);
        $this->assertIds(['parent_id' => $this->clause('=', [(string) $parent->id])], [$child->id]);
        $this->assertIds(['parent_id' => $this->clause('!*', [])], [$parent->id, $alone->id]);
        $this->assertIds(['parent_id' => $this->clause('*', [])], [$child->id]);
        $this->assertIds(['parent_id' => $this->clause('~', ['Parent'])], [$child->id]);
        $this->assertIds(['child_id' => $this->clause('=', [(string) $child->id])], [$parent->id]);
        $this->assertIds(['child_id' => $this->clause('!*', [])], [$child->id, $alone->id]);
        $this->assertIds(['child_id' => $this->clause('*', [])], [$parent->id]);
        $this->assertIds(['child_id' => $this->clause('~', ['Child'])], [$parent->id]);
        $this->assertSame([], $this->ids(['parent_id' => $this->clause('~', ['missing'])]));
    }

    public function test_filters_combine_with_and(): void
    {
        $match = $this->issue(['subject' => 'Fix login']);
        $this->issue(['subject' => 'Fix docs', 'status_id' => $this->world->closed->id]);
        $this->issue(['subject' => 'Docs']);

        $this->assertIds([
            'status_id' => $this->clause('o', []),
            'subject' => $this->clause('~', ['Fix']),
        ], [$match->id]);
    }

    public function test_custom_field_formats(): void
    {
        $list = $this->field('list', ['possible_values' => ['MySQL', 'PostgreSQL']]);
        $string = $this->field('string');
        $text = $this->field('text');
        $int = $this->field('int');
        $float = $this->field('float');
        $date = $this->field('date');
        $bool = $this->field('bool');
        $user = $this->field('user');
        $version = $this->field('version');
        $versionRow = Version::query()->create([
            'name' => '2.0',
            'project_id' => $this->world->project->id,
            'status' => 'open',
        ]);

        $mysql = $this->issue(['subject' => 'MySQL']);
        $this->value($mysql, $list, 'MySQL');
        $this->value($mysql, $string, 'acme widget');
        $this->value($mysql, $text, 'alpha notes');
        $this->value($mysql, $int, '10');
        $this->value($mysql, $float, '1.5');
        $this->value($mysql, $date, '2026-09-29');
        $this->value($mysql, $bool, '1');
        $this->value($mysql, $user, (string) $this->world->user->id);
        $this->value($mysql, $version, (string) $versionRow->id);

        $other = $this->issue(['subject' => 'Other']);
        $this->value($other, $list, 'PostgreSQL');
        $this->value($other, $int, '3');
        $blank = $this->issue(['subject' => 'Blank']);

        $this->assertIds(['cf_'.$list->id => $this->clause('=', ['MySQL'])], [$mysql->id]);
        $this->assertIds(['cf_'.$list->id => $this->clause('!', ['MySQL'])], [$other->id, $blank->id]);
        $this->assertIds(['cf_'.$list->id => $this->clause('!*', [])], [$blank->id]);
        $this->assertIds(['cf_'.$string->id => $this->clause('~', ['Acme'])], [$mysql->id]);
        $this->assertIds(['cf_'.$string->id => $this->clause('=', ['acme widget'])], [$mysql->id]);
        $this->assertIds(['cf_'.$text->id => $this->clause('~', ['alpha'])], [$mysql->id]);
        $this->assertIds(['cf_'.$int->id => $this->clause('>=', ['10'])], [$mysql->id]);
        $this->assertIds(['cf_'.$int->id => $this->clause('<=', ['3'])], [$other->id]);
        $this->assertIds(['cf_'.$int->id => $this->clause('!*', [])], [$blank->id]);
        $this->assertIds(['cf_'.$float->id => $this->clause('><', ['1', '2'])], [$mysql->id]);
        $this->assertIds(['cf_'.$date->id => $this->clause('t', [])], [$mysql->id]);
        $this->assertIds(['cf_'.$bool->id => $this->clause('=', ['1'])], [$mysql->id]);
        $this->assertIds(['cf_'.$user->id => $this->clause('=', [(string) $this->world->user->id])], [$mysql->id]);
        $this->assertIds(['cf_'.$user->id => $this->clause('=', ['me'])], [$mysql->id]);
        $this->assertIds(['cf_'.$version->id => $this->clause('=', [(string) $versionRow->id])], [$mysql->id]);
        $this->assertIds(['cf_'.$string->id => $this->clause('*~', ['widget missing'])], [$mysql->id]);
        $this->assertIds(['cf_'.$string->id => $this->clause('^', ['acme'])], [$mysql->id]);
        $this->assertIds(['cf_'.$string->id => $this->clause('$', ['widget'])], [$mysql->id]);
        $this->assertSame([], $this->ids(['cf_'.$string->id => $this->clause('^', ['widget'])]));
    }

    public function test_me_matches_the_current_user_only(): void
    {
        $other = User::factory()->create();
        $mine = $this->issue(['author_id' => $this->world->user->id, 'assigned_to_id' => $this->world->user->id, 'subject' => 'Mine']);
        $theirs = $this->issue(['author_id' => $other->id, 'assigned_to_id' => $other->id, 'subject' => 'Theirs']);
        $this->journal($theirs, 'assigned_to_id', (string) $this->world->user->id, (string) $other->id);

        $this->assertIds(['author_id' => $this->clause('=', ['me'])], [$mine->id]);
        $this->assertIds(['assigned_to_id' => $this->clause('=', ['me'])], [$mine->id]);
        $this->assertIds(['assigned_to_id' => $this->clause('ev', ['me'])], [$mine->id, $theirs->id]);
        $this->assertIds(['assigned_to_id' => $this->clause('!ev', ['me'])], []);
        $this->expectRejection(['tracker_id' => $this->clause('=', ['me'])]);
        $this->expectRejectionFor(null, ['author_id' => $this->clause('=', ['me'])]);

        $inactive = User::factory()->create(['status' => 0]);
        $this->expectRejectionFor($inactive, ['author_id' => $this->clause('=', ['me'])]);
        $anonymous = User::factory()->create(['type' => User::TYPE_ANONYMOUS]);
        $this->expectRejectionFor($anonymous, ['author_id' => $this->clause('=', ['me'])]);
        $group = User::factory()->create(['type' => User::TYPE_GROUP]);
        $this->expectRejectionFor($group, ['author_id' => $this->clause('=', ['me'])]);
    }

    public function test_history_operators_use_current_value_or_journal_details(): void
    {
        $wasClosed = $this->issue(['subject' => 'Was closed']);
        $this->journal($wasClosed, 'status_id', (string) $this->world->closed->id, (string) $this->world->newStatus->id);
        $stillClosed = $this->issue(['status_id' => $this->world->closed->id, 'subject' => 'Still closed']);
        $untouched = $this->issue(['subject' => 'Untouched']);
        $otherTracker = Tracker::query()->create([
            'name' => 'Feature',
            'default_status_id' => $this->world->newStatus->id,
            'position' => 3,
        ]);
        $changedTracker = $this->issue(['subject' => 'Changed tracker']);
        $this->journal($changedTracker, 'tracker_id', (string) $otherTracker->id, (string) $this->world->tracker->id);

        $closed = (string) $this->world->closed->id;
        $this->assertIds(['status_id' => $this->clause('ev', [$closed])], [$wasClosed->id, $stillClosed->id]);
        $this->assertIds(['status_id' => $this->clause('!ev', [$closed])], [$untouched->id, $changedTracker->id]);
        $this->assertIds(['status_id' => $this->clause('cf', [$closed])], [$wasClosed->id]);
        $this->assertIds(['tracker_id' => $this->clause('ev', [(string) $otherTracker->id])], [$changedTracker->id]);
        $this->assertNotContains($untouched->id, $this->ids(['tracker_id' => $this->clause('cf', [(string) $otherTracker->id])]));
    }

    public function test_relation_operators_follow_the_canonical_direction(): void
    {
        $blocker = $this->issue(['subject' => 'Blocker']);
        $blocked = $this->issue(['subject' => 'Blocked']);
        $closedBlocker = $this->issue(['status_id' => $this->world->closed->id, 'subject' => 'Closed blocker']);
        $closedVictim = $this->issue(['status_id' => $this->world->closed->id, 'subject' => 'Closed victim']);
        $lonely = $this->issue(['subject' => 'Lonely']);
        $otherProject = app(ProjectService::class)->create([
            'name' => 'Other',
            'identifier' => 'query-ops-other',
            'is_public' => true,
        ]);
        $foreign = $this->issue([
            'project_id' => $otherProject->id,
            'subject' => 'Foreign',
        ]);

        IssueRelation::query()->create([
            'issue_from_id' => $blocker->id,
            'issue_to_id' => $blocked->id,
            'relation_type' => 'blocks',
        ]);
        IssueRelation::query()->create([
            'issue_from_id' => $closedBlocker->id,
            'issue_to_id' => $closedVictim->id,
            'relation_type' => 'blocks',
        ]);
        IssueRelation::query()->create([
            'issue_from_id' => $blocker->id,
            'issue_to_id' => $foreign->id,
            'relation_type' => 'relates',
        ]);

        $same = (string) $this->world->project->id;
        $other = (string) $otherProject->id;
        $local = [$blocked->id, $closedBlocker->id, $closedVictim->id, $lonely->id];
        $notBlockers = [$blocked->id, $closedVictim->id, $lonely->id];
        $this->assertIds(['blocks' => $this->clause('*', [])], [$blocker->id, $closedBlocker->id]);
        $this->assertIds(['blocked' => $this->clause('*', [])], [$blocked->id, $closedVictim->id]);
        $this->assertIds(['blocks' => $this->clause('!*', [])], $notBlockers);
        $this->assertIds(['blocks' => $this->clause('=', [(string) $blocked->id])], [$blocker->id]);
        $this->assertIds(['blocks' => $this->clause('!', [(string) $blocked->id])], $local);
        $this->assertIds(['relates' => $this->clause('=p', [$other])], [$blocker->id]);
        $this->assertIds(['relates' => $this->clause('=!p', [$same])], [$blocker->id]);
        $this->assertIds(['relates' => $this->clause('!p', [$other])], $local);
        $this->assertIds(['blocks' => $this->clause('*o', [])], [$blocker->id]);
        $this->assertIds(['blocks' => $this->clause('!o', [])], [$closedBlocker->id, $blocked->id, $closedVictim->id, $lonely->id]);
        $this->assertNotContains($blocked->id, $this->ids(['blocked' => $this->clause('!o', [])]));
        $this->assertContains($closedVictim->id, $this->ids(['blocked' => $this->clause('!o', [])]));
    }

    public function test_version_custom_field_chained_due_date_and_status(): void
    {
        $version = $this->field('version');
        $open = Version::query()->create([
            'name' => 'Next',
            'project_id' => $this->world->project->id,
            'status' => 'open',
            'effective_date' => '2026-10-06',
        ]);
        $locked = Version::query()->create([
            'name' => 'Held',
            'project_id' => $this->world->project->id,
            'status' => 'locked',
            'effective_date' => null,
        ]);
        $scheduled = $this->issue(['subject' => 'Scheduled']);
        $this->value($scheduled, $version, (string) $open->id);
        $held = $this->issue(['subject' => 'Held']);
        $this->value($held, $version, (string) $locked->id);
        $blank = $this->issue(['subject' => 'Blank']);
        $string = $this->field('string');

        $due = 'cf_'.$version->id.'.due_date';
        $status = 'cf_'.$version->id.'.status';
        $this->assertIds([$due => $this->clause('nw', [])], [$scheduled->id]);
        $this->assertIds([$due => $this->clause('!*', [])], [$held->id, $blank->id]);
        $this->assertIds([$due => $this->clause('*', [])], [$scheduled->id]);
        $this->assertIds([$status => $this->clause('=', ['open'])], [$scheduled->id]);
        $this->assertIds([$status => $this->clause('!', ['open'])], [$held->id, $blank->id]);
        $this->expectRejection([$due => $this->clause('o', [])]);
        $this->expectRejection(['cf_'.$string->id.'.due_date' => $this->clause('t', [])]);
        $this->expectRejection(['cf_'.$version->id.'.name' => $this->clause('=', ['Next'])]);
    }

    public function test_hidden_unfiltered_and_deferred_filters_are_rejected(): void
    {
        $hidden = $this->field('list', [
            'possible_values' => ['A'],
            'visible' => false,
        ]);
        $stored = $this->field('string', ['is_filter' => false]);
        $link = $this->field('link');

        $this->expectRejection(['cf_'.$hidden->id => $this->clause('=', ['A'])]);
        $this->expectRejection(['cf_'.$stored->id => $this->clause('~', ['x'])]);
        $this->expectRejection(['cf_'.$link->id => $this->clause('~', ['x'])]);
        $this->expectRejection(['notes' => $this->clause('~', ['x'])]);
        $this->expectRejection(['subproject_id' => $this->clause('*', [])]);
        $this->expectRejection(['watcher_id' => $this->clause('=', ['1'])]);
        $this->expectRejection(['attachment' => $this->clause('*', [])]);
        $this->expectRejection(['any_searchable' => $this->clause('~', ['x'])]);
        $this->expectRejection(['fixed_version.due_date' => $this->clause('t', [])]);
        $this->expectRejection(['done_ratio' => $this->clause('!', ['1'])]);
        $this->expectRejection(['estimated_hours' => $this->clause('!', ['1'])]);
        $hiddenVersion = $this->field('version', ['visible' => false]);
        $this->expectRejection(['cf_'.$hiddenVersion->id.'.status' => $this->clause('=', ['open'])]);
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<int>  $expected
     */
    private function assertIds(array $filters, array $expected): void
    {
        $ids = $this->ids($filters);
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @return list<int>
     */
    private function ids(array $filters): array
    {
        $ids = [];
        foreach (app(IssueQueryRunner::class)->preview($this->world->user, $this->world->project, $filters)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     */
    private function expectRejection(array $filters): void
    {
        $this->expectRejectionFor($this->world->user, $filters);
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     */
    private function expectRejectionFor(?User $actor, array $filters): void
    {
        try {
            app(IssueQueryRunner::class)->preview($actor, $this->world->project, $filters)->pluck('id');
            $this->fail('Filter should have been rejected.');
        } catch (QueryValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param  list<string>  $values
     * @return array{operator: string, values: list<string>}
     */
    private function clause(string $operator, array $values): array
    {
        return ['operator' => $operator, 'values' => $values];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function issue(array $overrides): Issue
    {
        return Issue::query()->create([
            'project_id' => $this->world->project->id,
            'tracker_id' => $this->world->tracker->id,
            'status_id' => $this->world->newStatus->id,
            'priority_id' => $this->world->priority->id,
            'author_id' => $this->world->user->id,
            'subject' => 'Subject',
            'done_ratio' => 0,
            'is_private' => false,
            'lock_version' => 0,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function field(string $format, array $overrides = []): CustomField
    {
        return CustomField::query()->create([
            'name' => substr($format.bin2hex(random_bytes(4)), 0, 30),
            'field_format' => $format,
            'type' => 'IssueCustomField',
            'is_filter' => true,
            'is_for_all' => true,
            'visible' => true,
            'editable' => true,
            'position' => 1,
            ...$overrides,
        ]);
    }

    private function value(Issue $issue, CustomField $field, string $value): void
    {
        CustomValue::query()->create([
            'custom_field_id' => $field->id,
            'customized_type' => 'Issue',
            'customized_id' => $issue->id,
            'value' => $value,
        ]);
    }

    private function journal(Issue $issue, string $column, string $old, string $new): void
    {
        $journal = Journal::query()->create([
            'journalized_id' => $issue->id,
            'journalized_type' => 'Issue',
            'user_id' => $this->world->user->id,
            'notes' => '',
        ]);
        JournalDetail::query()->create([
            'journal_id' => $journal->id,
            'property' => 'attr',
            'prop_key' => $column,
            'old_value' => $old,
            'value' => $new,
        ]);
    }
}
