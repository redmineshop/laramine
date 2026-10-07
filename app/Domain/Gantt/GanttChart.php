<?php

namespace App\Domain\Gantt;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Auth\PreferenceCodec;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\VersionAvailability;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\IssueTreeHours;
use App\Domain\Settings\SettingValue;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Version;
use DateTimeImmutable;

/**
 * Gantt rows, bars, and the relations drawn between them.
 *
 * Issues come from the open-status IssueQuery, ordered by project `lft`
 * then issue id, and capped by `gantt_items_limit` (500 when the setting
 * is missing, unlimited when it is blank). Projects are those issues'
 * visible ancestors. Under each project, unversioned issues come first,
 * then versions that still have an issue in that set (including a closed
 * version), then the issues of each version. Issue order follows ancestor
 * start dates. A missing start sorts before any date. Bars need both ends
 * inside or overlapping the month window. `blocks` and `precedes` are
 * drawn when both issues were loaded and `draw_relations` is not `0`.
 * PNG export needs the optional image gem, so it is not produced.
 */
final class GanttChart
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly IssueQueryRunner $issues,
        private readonly IssueVisibility $issueVisibility,
        private readonly IssueTreeHours $treeHours,
        private readonly SettingValue $settings,
        private readonly VersionAvailability $versions,
        private readonly PreferenceCodec $preferences,
        private readonly GanttPdf $pdf,
    ) {}

    /**
     * @param  array{year?: int|null, month?: int|null, months?: int|null, zoom?: int|null, max_rows?: int|null}  $input
     * @param  array<string, array{operator: string, values: list<string>}>|null  $filters
     * @param  array<string, mixed>|null  $options
     * @return array<string, mixed>
     */
    public function show(?User $actor, ?Project $project, array $input, ?array $filters, ?array $options, ?DateTimeImmutable $today = null): array
    {
        $this->authorize($actor, $project);
        $today ??= new DateTimeImmutable('today');
        $window = $this->window($actor, $input, $today);
        $maxRows = array_key_exists('max_rows', $input) ? $input['max_rows'] : $this->settings->ganttItemsLimit();
        $filters ??= ['status_id' => ['operator' => 'o', 'values' => []]];
        $loaded = $this->loadIssues($actor, $project, $filters, $maxRows);
        $projects = $this->projects($actor, $loaded);
        $relations = $this->relations($loaded, $options);
        [$rows, $truncated] = $this->rows($actor, $projects, $loaded, $window, $today, $maxRows);
        $labels = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $labels[] = is_string($name) ? $name : '';
        }

        return [
            'year_from' => $window['year'],
            'month_from' => $window['month'],
            'date_from' => $window['from'],
            'date_to' => $window['to'],
            'zoom' => $window['zoom'],
            'months' => $window['months'],
            'truncated' => $truncated,
            'max_rows' => $maxRows,
            'png' => 'n/a',
            'rows' => $rows,
            'relations' => $relations,
            'pdf' => $this->pdf->render($window['from'].' '.$window['to'], $labels),
        ];
    }

    private function authorize(?User $actor, ?Project $project): void
    {
        if ($project !== null) {
            if (! $this->permissions->allowed($actor, 'view_gantt', $project)) {
                throw new PermissionDeniedException('view_gantt');
            }

            return;
        }

        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return;
        }

        foreach (Project::query()->orderBy('id')->get() as $candidate) {
            if ($this->permissions->allowed($actor, 'view_gantt', $candidate)) {
                return;
            }
        }

        throw new PermissionDeniedException('view_gantt');
    }

    /**
     * @param  array{year?: int|null, month?: int|null, months?: int|null, zoom?: int|null, max_rows?: int|null}  $input
     * @return array{year: int, month: int, from: string, to: string, zoom: int, months: int}
     */
    private function window(?User $actor, array $input, DateTimeImmutable $today): array
    {
        $stored = $this->storedPreference($actor);
        $zoomSource = $input['zoom'] ?? (isset($stored['gantt_zoom']) ? (int) $stored['gantt_zoom'] : 0);
        $monthSource = $input['months'] ?? (isset($stored['gantt_months']) ? (int) $stored['gantt_months'] : 0);
        $zoom = $zoomSource > 0 && $zoomSource < 5 ? $zoomSource : 2;
        $limit = $this->settings->ganttMonthsLimit();
        $months = $monthSource > 0 && $monthSource < $limit + 1 ? $monthSource : 6;
        $this->remember($actor, $stored, $zoom, $months);

        $year = $input['year'] ?? null;
        if ($year !== null && $year > 0) {
            $yearFrom = $year;
            $month = $input['month'] ?? null;
            $monthFrom = $month !== null && $month >= 1 && $month <= 12 ? $month : 1;
        } else {
            $yearFrom = (int) $today->format('Y');
            $monthFrom = (int) $today->format('n');
        }

        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $yearFrom, $monthFrom));
        $to = $from->modify('+'.$months.' months')->modify('-1 day');

        return [
            'year' => $yearFrom,
            'month' => $monthFrom,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'zoom' => $zoom,
            'months' => $months,
        ];
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @return list<Issue>
     */
    private function loadIssues(?User $actor, ?Project $project, array $filters, ?int $maxRows): array
    {
        $query = $this->issues->matching($actor, $project, $filters)
            ->orderByRaw('(SELECT projects.lft FROM projects WHERE projects.id = issues.project_id) asc')
            ->orderBy('issues.id');
        if ($maxRows !== null) {
            $query->limit($maxRows);
        }

        $issues = [];
        foreach ($query->get() as $issue) {
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * @param  list<Issue>  $issues
     * @return list<Project>
     */
    private function projects(?User $actor, array $issues): array
    {
        $ids = [];
        foreach ($issues as $issue) {
            $ids[(int) $issue->project_id] = true;
        }
        if ($ids === []) {
            return [];
        }

        $projects = [];
        $candidates = Project::query()
            ->whereExists(function ($sub) use ($ids): void {
                $sub->selectRaw('1')
                    ->from('projects as gantt_child')
                    ->whereIn('gantt_child.id', array_keys($ids))
                    ->whereColumn('projects.lft', '<=', 'gantt_child.lft')
                    ->whereColumn('projects.rgt', '>=', 'gantt_child.rgt');
            })
            ->orderBy('lft')
            ->get();
        foreach ($candidates as $candidate) {
            if ($this->permissions->projectVisible($actor, $candidate)) {
                $projects[] = $candidate;
            }
        }

        return $projects;
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array<string, mixed>|null  $options
     * @return list<array{from: int, to: int, type: string}>
     */
    private function relations(array $issues, ?array $options): array
    {
        $draw = $options['draw_relations'] ?? null;
        if ($draw === '0' || $draw === 0 || $draw === false) {
            return [];
        }

        $ids = [];
        foreach ($issues as $issue) {
            $ids[] = (int) $issue->id;
        }
        if ($ids === []) {
            return [];
        }

        $relations = [];
        foreach (IssueRelation::query()
            ->whereIn('issue_from_id', $ids)
            ->whereIn('issue_to_id', $ids)
            ->whereIn('relation_type', ['blocks', 'precedes'])
            ->orderBy('id')
            ->get() as $relation) {
            $relations[] = [
                'from' => (int) $relation->issue_from_id,
                'to' => (int) $relation->issue_to_id,
                'type' => (string) $relation->relation_type,
            ];
        }

        return $relations;
    }

    /**
     * @param  list<Project>  $projects
     * @param  list<Issue>  $issues
     * @param  array{year: int, month: int, from: string, to: string, zoom: int, months: int}  $window
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function rows(?User $actor, array $projects, array $issues, array $window, DateTimeImmutable $today, ?int $maxRows): array
    {
        $byProject = [];
        foreach ($issues as $issue) {
            $byProject[(int) $issue->project_id][] = $issue;
        }
        $rows = [];
        $stop = false;
        foreach ($projects as $project) {
            $level = 0;
            foreach ($projects as $ancestor) {
                if ((int) $ancestor->lft < (int) $project->lft && (int) $ancestor->rgt > (int) $project->rgt) {
                    $level++;
                }
            }
            $this->push($rows, $stop, $maxRows, $this->projectRow($project, $level, $window));
            if ($stop) {
                break;
            }
            $owned = $byProject[(int) $project->id] ?? [];
            $unversioned = [];
            foreach ($owned as $issue) {
                if ($issue->fixed_version_id === null) {
                    $unversioned[] = $issue;
                }
            }
            $this->issueRows($actor, $unversioned, $level + 1, $window, $today, $rows, $stop, $maxRows);
            if ($stop) {
                break;
            }
            foreach ($this->sortedVersions($this->projectVersions($owned)) as $version) {
                $this->push($rows, $stop, $maxRows, $this->versionRow($actor, $version, $level + 1, $window, $today));
                if ($stop) {
                    break;
                }
                $assigned = [];
                foreach ($owned as $issue) {
                    if ((int) $issue->fixed_version_id === (int) $version->id) {
                        $assigned[] = $issue;
                    }
                }
                $this->issueRows($actor, $assigned, $level + 2, $window, $today, $rows, $stop, $maxRows);
                if ($stop) {
                    break;
                }
            }
        }

        return [$rows, $stop];
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array{year: int, month: int, from: string, to: string, zoom: int, months: int}  $window
     * @param  list<array<string, mixed>>  $rows
     */
    private function issueRows(?User $actor, array $issues, int $indent, array $window, DateTimeImmutable $today, array &$rows, bool &$stop, ?int $maxRows): void
    {
        $this->sortIssues($issues);
        $ancestors = [];
        foreach ($issues as $issue) {
            while ($ancestors !== []) {
                $parent = $ancestors[array_key_last($ancestors)];
                if ($this->isDescendant($issue, $parent)) {
                    break;
                }
                array_pop($ancestors);
            }
            $this->push($rows, $stop, $maxRows, $this->issueRow($issue, $indent + count($ancestors), $window, $today));
            if ($stop) {
                return;
            }
            if ((int) $issue->rgt - (int) $issue->lft > 1) {
                $ancestors[] = $issue;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $row
     */
    private function push(array &$rows, bool &$stop, ?int $maxRows, array $row): void
    {
        if ($stop) {
            return;
        }
        if ($maxRows !== null && count($rows) >= $maxRows) {
            $stop = true;

            return;
        }
        $rows[] = $row;
        if ($maxRows !== null && count($rows) >= $maxRows) {
            $stop = true;
        }
    }

    /**
     * @param  array{year: int, month: int, from: string, to: string, zoom: int, months: int}  $window
     * @return array<string, mixed>
     */
    private function projectRow(Project $project, int $indent, array $window): array
    {
        $start = $this->projectStart($project);
        $end = $this->projectEnd($project);

        return [
            'kind' => 'project',
            'id' => (int) $project->id,
            'indent' => $indent,
            'name' => (string) $project->name,
            'status' => (string) $project->status,
            'bar' => $this->bar($start, $end, null, true, $window, new DateTimeImmutable('today')),
        ];
    }

    /**
     * @param  array{year: int, month: int, from: string, to: string, zoom: int, months: int}  $window
     * @return array<string, mixed>
     */
    private function versionRow(?User $actor, Version $version, int $indent, array $window, DateTimeImmutable $today): array
    {
        $start = $this->day(Issue::query()->where('fixed_version_id', $version->id)->min('start_date'));
        $end = $this->day($version->effective_date);
        $progress = $start !== null && $end !== null ? $this->versionPercent($actor, $version) : null;

        return [
            'kind' => 'version',
            'id' => (int) $version->id,
            'indent' => $indent,
            'name' => (string) $version->name,
            'status' => (string) $version->status,
            'bar' => $this->bar($start, $end, $progress, true, $window, $today),
        ];
    }

    /**
     * @param  array{year: int, month: int, from: string, to: string, zoom: int, months: int}  $window
     * @return array<string, mixed>
     */
    private function issueRow(Issue $issue, int $indent, array $window, DateTimeImmutable $today): array
    {
        $status = IssueStatus::query()->find($issue->status_id);
        $due = $this->day($issue->due_date);
        if ($due === null && $issue->fixed_version_id !== null) {
            $version = Version::query()->find($issue->fixed_version_id);
            $due = $version instanceof Version ? $this->day($version->effective_date) : null;
        }

        return [
            'kind' => 'issue',
            'id' => (int) $issue->id,
            'indent' => $indent,
            'name' => (string) $issue->subject,
            'status' => $status instanceof IssueStatus ? (string) $status->name : null,
            'bar' => $this->bar(
                $this->day($issue->start_date),
                $due,
                (int) $issue->done_ratio,
                (int) $issue->rgt - (int) $issue->lft > 1,
                $window,
                $today,
            ),
        ];
    }

    /**
     * @param  array{year: int, month: int, from: string, to: string, zoom: int, months: int}  $window
     * @return array<string, mixed>|null
     */
    private function bar(?string $start, ?string $end, ?float $progress, bool $markers, array $window, DateTimeImmutable $today): ?array
    {
        if ($start === null || $end === null || $start > $window['to'] || $end < $window['from']) {
            return null;
        }

        $coords = [];
        if ($start >= $window['from']) {
            $coords['bar_start'] = $this->diffDays($window['from'], $start);
        } else {
            $coords['bar_start'] = 0;
        }
        if ($end <= $window['to']) {
            $coords['bar_end'] = $this->diffDays($window['from'], $end) + 1;
        } else {
            $coords['bar_end'] = $this->diffDays($window['from'], $window['to']) + 1;
        }

        if ($start >= $window['from'] && $start <= $window['to']) {
            $coords['marker_start'] = $this->diffDays($window['from'], $start);
        }
        if ($end >= $window['from'] && $end <= $window['to']) {
            $coords['marker_end'] = $this->diffDays($window['from'], $end) + 1;
        }

        if ($progress !== null && $progress > 0) {
            $span = $this->diffDays($start, $end) + 1;
            $progressDate = $this->addDays($start, (int) floor($span * ($progress / 100)));
            $todayText = $today->format('Y-m-d');
            if ($progressDate > $window['from'] && $progressDate > $start) {
                $coords['bar_progress_end'] = $progressDate < $window['to']
                    ? $this->diffDays($window['from'], $progressDate)
                    : $this->diffDays($window['from'], $window['to']) + 1;
            }
            if ($progressDate <= $todayText) {
                $lateDate = $this->addDays(min($todayText, $end), 1);
                if ($lateDate > $window['from'] && $lateDate > $start) {
                    $coords['bar_late_end'] = $lateDate < $window['to']
                        ? $this->diffDays($window['from'], $lateDate)
                        : $this->diffDays($window['from'], $window['to']) + 1;
                }
            }
        }

        $zoom = $window['zoom'];
        $scaled = [];
        foreach ($coords as $key => $value) {
            $scaled[$key] = (int) floor($value * $zoom);
        }

        return [
            'start' => $start,
            'end' => $end,
            'bar_start' => $scaled['bar_start'],
            'bar_end' => $scaled['bar_end'],
            'bar_progress_end' => $scaled['bar_progress_end'] ?? null,
            'bar_late_end' => $scaled['bar_late_end'] ?? null,
            'marker_start' => $scaled['marker_start'] ?? null,
            'marker_end' => $scaled['marker_end'] ?? null,
            'progress' => $progress === null ? null : (int) round($progress),
            'markers' => $markers,
        ];
    }

    private function projectStart(Project $project): ?string
    {
        return $this->earliest([
            $this->day(Issue::query()->where('project_id', $project->id)->min('start_date')),
            $this->day(Version::query()->whereIn('id', $this->sharedVersionIds($project))->min('effective_date')),
            $this->day(Issue::query()->whereIn('fixed_version_id', $this->sharedVersionIds($project))->min('start_date')),
        ]);
    }

    private function projectEnd(Project $project): ?string
    {
        return $this->latest([
            $this->day(Issue::query()->where('project_id', $project->id)->max('due_date')),
            $this->day(Version::query()->whereIn('id', $this->sharedVersionIds($project))->max('effective_date')),
            $this->day(Issue::query()->whereIn('fixed_version_id', $this->sharedVersionIds($project))->max('due_date')),
        ]);
    }

    /**
     * @return list<int>
     */
    private function sharedVersionIds(Project $project): array
    {
        $ids = [];
        foreach (Version::query()->orderBy('id')->get() as $version) {
            if ($this->versions->available($version, $project)) {
                $ids[] = (int) $version->id;
            }
        }

        return $ids === [] ? [-1] : $ids;
    }

    /**
     * @param  list<Issue>  $issues
     * @return list<Version>
     */
    private function projectVersions(array $issues): array
    {
        $ids = [];
        foreach ($issues as $issue) {
            if ($issue->fixed_version_id !== null) {
                $ids[(int) $issue->fixed_version_id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $versions = [];
        foreach (Version::query()->whereIn('id', array_keys($ids))->get() as $version) {
            $versions[] = $version;
        }

        return $versions;
    }

    /**
     * @param  list<Version>  $versions
     * @return list<Version>
     */
    private function sortedVersions(array $versions): array
    {
        usort($versions, function (Version $left, Version $right): int {
            $leftDate = $this->day($left->effective_date);
            $rightDate = $this->day($right->effective_date);
            if ($leftDate === null && $rightDate === null) {
                // Fall through to the name.
            } elseif ($leftDate === null) {
                return 1;
            } elseif ($rightDate === null) {
                return -1;
            } elseif ($leftDate !== $rightDate) {
                return $leftDate <=> $rightDate;
            }
            $byName = strcmp((string) $left->name, (string) $right->name);
            if ($byName !== 0) {
                return $byName;
            }

            return (int) $left->id <=> (int) $right->id;
        });

        return $versions;
    }

    /**
     * @param  list<Issue>  $issues
     */
    private function sortIssues(array &$issues): void
    {
        $keys = [];
        foreach ($issues as $issue) {
            $keys[(int) $issue->id] = $this->issueKey($issue);
        }
        usort($issues, function (Issue $left, Issue $right) use ($keys): int {
            return $this->compareKeys($keys[(int) $left->id], $keys[(int) $right->id]);
        });
    }

    /**
     * @return list<array{0: ?string, 1: int}>
     */
    private function issueKey(Issue $issue): array
    {
        $pairs = [];
        $current = $issue;
        $seen = [];
        for ($guard = 0; $guard < 64; $guard++) {
            $id = (int) $current->id;
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $pairs[] = [$this->day($current->start_date), $id];
            if ($current->parent_id === null) {
                break;
            }
            $parent = Issue::query()->find((int) $current->parent_id);
            if (! $parent instanceof Issue) {
                break;
            }
            $current = $parent;
        }

        return array_reverse($pairs);
    }

    /**
     * @param  list<array{0: ?string, 1: int}>  $left
     * @param  list<array{0: ?string, 1: int}>  $right
     */
    private function compareKeys(array $left, array $right): int
    {
        $length = min(count($left), count($right));
        for ($index = 0; $index < $length; $index++) {
            $compared = $this->comparePair($left[$index], $right[$index]);
            if ($compared !== 0) {
                return $compared;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * @param  array{0: ?string, 1: int}  $left
     * @param  array{0: ?string, 1: int}  $right
     */
    private function comparePair(array $left, array $right): int
    {
        $leftDay = $left[0];
        $rightDay = $right[0];
        if ($leftDay === null && $rightDay === null) {
            // Fall through to the id.
        } elseif ($leftDay === null) {
            return -1;
        } elseif ($rightDay === null) {
            return 1;
        } elseif ($leftDay !== $rightDay) {
            return $leftDay <=> $rightDay;
        }

        return $left[1] <=> $right[1];
    }

    private function isDescendant(Issue $issue, Issue $ancestor): bool
    {
        return (int) $issue->root_id === (int) $ancestor->root_id
            && (int) $issue->lft > (int) $ancestor->lft
            && (int) $issue->rgt < (int) $ancestor->rgt;
    }

    private function versionPercent(?User $actor, Version $version): float
    {
        $visible = [];
        foreach (Issue::query()->where('fixed_version_id', $version->id)->orderBy('id')->get() as $issue) {
            if ($this->issueVisibility->canSee($actor, $issue)) {
                $visible[] = $issue;
            }
        }
        if ($visible === []) {
            return 0.0;
        }

        $open = [];
        $closed = [];
        foreach ($visible as $issue) {
            $status = IssueStatus::query()->find($issue->status_id);
            if ($status instanceof IssueStatus && $status->is_closed) {
                $closed[] = $issue;
            } else {
                $open[] = $issue;
            }
        }
        if ($open === []) {
            return 100.0;
        }

        $ids = [];
        foreach ($visible as $issue) {
            $ids[] = (int) $issue->id;
        }
        $totals = $this->treeHours->estimated($actor, $ids);
        $positive = [];
        foreach ($totals as $hours) {
            $number = (float) $hours;
            if ($number > 0) {
                $positive[] = $number;
            }
        }
        $average = $positive === [] ? 1.0 : array_sum($positive) / count($positive);
        $denominator = $average * count($visible);

        return $this->portion($closed, $totals, $average, false, $denominator)
            + $this->portion($open, $totals, $average, true, $denominator);
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array<int, string>  $totals
     */
    private function portion(array $issues, array $totals, float $average, bool $useRatio, float $denominator): float
    {
        if ($denominator == 0.0) {
            return 0.0;
        }
        $done = 0.0;
        foreach ($issues as $issue) {
            $estimated = (float) ($totals[(int) $issue->id] ?? '0');
            if ($estimated <= 0) {
                $estimated = $average;
            }
            $ratio = $useRatio ? (int) $issue->done_ratio : 100;
            $done += $estimated * $ratio;
        }

        return $done / $denominator;
    }

    /**
     * @param  list<?string>  $dates
     */
    private function earliest(array $dates): ?string
    {
        $best = null;
        foreach ($dates as $date) {
            if ($date !== null && ($best === null || $date < $best)) {
                $best = $date;
            }
        }

        return $best;
    }

    /**
     * @param  list<?string>  $dates
     */
    private function latest(array $dates): ?string
    {
        $best = null;
        foreach ($dates as $date) {
            if ($date !== null && ($best === null || $date > $best)) {
                $best = $date;
            }
        }

        return $best;
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

    private function diffDays(string $from, string $to): int
    {
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);

        return (int) $start->diff($end)->format('%r%a');
    }

    private function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    /**
     * @return array<string, bool|string>
     */
    private function storedPreference(?User $actor): array
    {
        if (! $actor instanceof User) {
            return [];
        }
        $preference = UserPreference::query()->where('user_id', $actor->id)->first();

        return $this->preferences->decode($preference instanceof UserPreference && is_string($preference->others) ? $preference->others : null);
    }

    /**
     * @param  array<string, bool|string>  $stored
     */
    private function remember(?User $actor, array $stored, int $zoom, int $months): void
    {
        if (! $actor instanceof User || ! $actor->isActive() || $actor->type !== User::TYPE_USER) {
            return;
        }
        $zoomText = (string) $zoom;
        $monthsText = (string) $months;
        if (($stored['gantt_zoom'] ?? null) === $zoomText && ($stored['gantt_months'] ?? null) === $monthsText) {
            return;
        }
        $stored['gantt_zoom'] = $zoomText;
        $stored['gantt_months'] = $monthsText;
        $preference = UserPreference::query()->firstOrNew(['user_id' => $actor->id]);
        if (! $preference->exists) {
            $preference->hide_mail = true;
            $preference->time_zone = '';
        }
        $preference->others = $this->preferences->encode($stored);
        $preference->save();
    }
}
