<?php

namespace App\Domain\Calendar;

use App\Domain\Acl\PermissionService;
use App\Domain\PermissionDeniedException;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\IssueQuerySelection;
use App\Domain\Queries\QueryFilter;
use App\Domain\Queries\SubprojectScope;
use App\Domain\Settings\SettingValue;
use App\Models\EnabledModule;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use DateTimeImmutable;

/**
 * Month grid for issues and versions.
 *
 * The window is the month plus the days needed to start on `start_of_week`
 * and finish on the last day of that week. Blank, or any value other than
 * 1, 6, or 7, uses Sunday, the English first day of the week. An issue is
 * included when `start_date` or `due_date` falls in that window. A version
 * is included when `effective_date` does. Once included, a version also
 * sits on the earliest start date of its issues. The issue list is the
 * open-status IssueQuery for the project (and its descendants, unless that
 * setting is off), ordered by id descending. Versions follow, by id.
 */
final class CalendarService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly IssueQueryRunner $issues,
        private readonly IssueQuerySelection $selection,
        private readonly SettingValue $settings,
        private readonly SubprojectScope $subprojects,
    ) {}

    /**
     * @param  array<string, array{operator: string, values: list<string>}>|null  $filters
     * @param  list<array{0: string, 1: string}>|null  $issueSort
     * @return array{
     *     year: int,
     *     month: int,
     *     start: string,
     *     end: string,
     *     first_wday: int,
     *     days: list<array{date: string, in_month: bool, non_working: bool, week: int, events: list<array{kind: string, id: int}>}>
     * }
     */
    public function month(?User $actor, ?Project $project, ?int $year, ?int $month, ?array $filters = null, ?DateTimeImmutable $today = null, ?array $issueSort = null): array
    {
        $this->authorize($actor, $project);
        $today ??= new DateTimeImmutable('today');
        $yearGiven = $year !== null && $year > 1900;
        $resolvedYear = $yearGiven ? $year : (int) $today->format('Y');
        $resolvedMonth = $yearGiven && $month !== null && $month >= 1 && $month <= 12
            ? $month
            : (int) $today->format('n');

        $firstWday = $this->settings->startOfWeek();
        $window = $this->window($resolvedYear, $resolvedMonth, $firstWday);
        $events = $this->events($actor, $project, $filters ?? ['status_id' => ['operator' => 'o', 'values' => []]], $window['start'], $window['end'], $issueSort);
        $nonWorking = array_fill_keys($this->settings->nonWorkingWeekDays(), true);
        $days = [];
        $cursor = $window['start_date'];
        while ($cursor <= $window['end_date']) {
            $date = $cursor->format('Y-m-d');
            $cwday = (int) $cursor->format('N');
            $days[] = [
                'date' => $date,
                'in_month' => (int) $cursor->format('n') === $resolvedMonth,
                'non_working' => isset($nonWorking[$cwday]),
                'week' => (int) $cursor->modify('+'.((11 - $cwday) % 7).' days')->format('W'),
                'events' => $events[$date] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'year' => $resolvedYear,
            'month' => $resolvedMonth,
            'start' => $window['start'],
            'end' => $window['end'],
            'first_wday' => $firstWday,
            'days' => $days,
        ];
    }

    public function authorize(?User $actor, ?Project $project): void
    {
        if ($project !== null) {
            if (! $this->permissions->allowed($actor, 'view_calendar', $project)) {
                throw new PermissionDeniedException('view_calendar');
            }

            return;
        }

        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->permissions->allowed($actor, 'view_calendar', $candidate)) {
                return;
            }
        }

        throw new PermissionDeniedException('view_calendar');
    }

    /**
     * Month grid for a saved query or an explicit filter set.
     *
     * Calendar does not restore a session and does not apply the default
     * issue query. `group_by` is ignored. Issue order follows the query sort.
     *
     * @param  array<string, mixed>  $params
     * @return array{
     *     year: int,
     *     month: int,
     *     start: string,
     *     end: string,
     *     first_wday: int,
     *     days: list<array{date: string, in_month: bool, non_working: bool, week: int, events: list<array{kind: string, id: int}>}>
     * }
     */
    public function monthForQuery(?User $actor, ?Project $project, ?int $year, ?int $month, array $params, ?DateTimeImmutable $today = null): array
    {
        $selected = $this->selection->select($actor, $project, $params, null, false, false);

        return $this->month($actor, $project, $year, $month, $selected['filters'], $today, $selected['sort']);
    }

    /**
     * @return array{start: string, end: string, start_date: DateTimeImmutable, end_date: DateTimeImmutable}
     */
    private function window(int $year, int $month, int $firstWday): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('+1 month')->modify('-1 day');
        $lastWday = (($firstWday + 5) % 7) + 1;
        $start = $start->modify('-'.$this->positiveMod((int) $start->format('N') - $firstWday, 7).' days');
        $end = $end->modify('+'.$this->positiveMod($lastWday - (int) $end->format('N'), 7).' days');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<array{0: string, 1: string}>|null  $issueSort
     * @return array<string, list<array{kind: string, id: int}>>
     */
    private function events(?User $actor, ?Project $project, array $filters, string $start, string $end, ?array $issueSort = null): array
    {
        if ($issueSort === null) {
            $issues = $this->issues->matching($actor, $project, $filters)
                ->orderByDesc('issues.id')
                ->get();
        } else {
            $issues = $this->issues->preview($actor, $project, $filters, $issueSort)->get();
        }
        $rows = [];
        foreach ($issues as $issue) {
            if ($this->inWindow($this->day($issue->start_date), $start, $end) || $this->inWindow($this->day($issue->due_date), $start, $end)) {
                $rows[] = $issue;
            }
        }

        $versions = $this->versions($actor, $project, $filters, $start, $end);
        $ending = [];
        $starting = [];
        foreach ($rows as $issue) {
            $this->place($ending, $this->day($issue->due_date), 'issue', (int) $issue->id);
            $this->place($starting, $this->day($issue->start_date), 'issue', (int) $issue->id);
        }
        foreach ($versions as $version) {
            $this->place($ending, $this->day($version->effective_date), 'version', (int) $version->id);
            $this->place($starting, $this->versionStart($version), 'version', (int) $version->id);
        }

        $days = [];
        foreach (array_unique([...array_keys($ending), ...array_keys($starting)]) as $date) {
            $seen = [];
            $events = [];
            foreach ([...($ending[$date] ?? []), ...($starting[$date] ?? [])] as $event) {
                $key = $event['kind'].':'.$event['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $events[] = $event;
            }
            $days[$date] = $events;
        }

        return $days;
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @return list<Version>
     */
    private function versions(?User $actor, ?Project $project, array $filters, string $start, string $end): array
    {
        $projectIds = $this->versionProjects($actor, $project, $filters);
        if ($projectIds === []) {
            return [];
        }

        $versions = [];
        foreach (Version::query()
            ->whereIn('project_id', $projectIds)
            ->whereBetween('effective_date', [$start, $end])
            ->orderBy('id')
            ->get() as $version) {
            $owner = $version->project;
            if ($owner instanceof Project && $this->permissions->allowed($actor, 'view_issues', $owner)) {
                $versions[] = $version;
            }
        }

        return $versions;
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @return list<int>
     */
    private function versionProjects(?User $actor, ?Project $project, array $filters): array
    {
        $subproject = [];
        foreach (QueryFilter::listFromMap($filters) as $filter) {
            if ($filter->field === 'subproject_id') {
                $subproject[] = $filter;
            }
        }

        if ($project === null) {
            $ids = [];
            foreach (Project::query()->orderBy('id')->get() as $candidate) {
                if ($this->permissions->allowed($actor, 'view_issues', $candidate)) {
                    $ids[] = (int) $candidate->id;
                }
            }
            if ($subproject === []) {
                return $ids;
            }

            return array_values(array_intersect($ids, $this->globalSubprojectIds($subproject)));
        }

        $ids = [];
        foreach ($this->subprojects->ids($project, $subproject) as $id) {
            $candidate = Project::query()->find($id);
            if ($candidate instanceof Project && $this->permissions->allowed($actor, 'view_issues', $candidate)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<QueryFilter>  $filters
     * @return list<int>
     */
    private function globalSubprojectIds(array $filters): array
    {
        $ids = [];
        foreach (Project::query()->orderBy('id')->get() as $project) {
            $ids[] = (int) $project->id;
        }
        foreach ($filters as $filter) {
            $this->subprojects->assert($filter);
            if ($filter->operator === '*') {
                continue;
            }
            if ($filter->operator === '!*') {
                $roots = [];
                foreach (Project::query()->whereNull('parent_id')->pluck('id') as $id) {
                    if (is_numeric($id)) {
                        $roots[] = (int) $id;
                    }
                }
                $ids = array_values(array_intersect($ids, $roots));

                continue;
            }
            $listed = [];
            foreach ($filter->values as $value) {
                if (preg_match('/^\d+$/', $value) === 1) {
                    $listed[] = (int) $value;
                }
            }
            $ids = $filter->operator === '='
                ? array_values(array_intersect($ids, $listed))
                : array_values(array_diff($ids, $listed));
        }

        return $ids;
    }

    private function versionStart(Version $version): ?string
    {
        $min = Issue::query()->where('fixed_version_id', $version->id)->min('start_date');

        return $this->day($min);
    }

    /**
     * @param  array<string, list<array{kind: string, id: int}>>  $bucket
     */
    private function place(array &$bucket, ?string $date, string $kind, int $id): void
    {
        if ($date === null) {
            return;
        }
        $bucket[$date][] = ['kind' => $kind, 'id' => $id];
    }

    private function inWindow(?string $date, string $start, string $end): bool
    {
        return $date !== null && $date >= $start && $date <= $end;
    }

    private function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function positiveMod(int $value, int $mod): int
    {
        return ($value % $mod + $mod) % $mod;
    }

    /**
     * Modules recorded on the project. Used by the page, not the grid.
     */
    public function moduleEnabled(Project $project): bool
    {
        return EnabledModule::query()
            ->where('project_id', $project->id)
            ->where('name', 'calendar')
            ->exists();
    }
}
