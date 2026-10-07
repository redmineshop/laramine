<?php

namespace App\Domain\TimeEntries;

use App\Domain\Acl\IssueVisibility;
use App\Domain\CustomFields\CustomFieldTypes;
use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\Queries\DateWindow;
use App\Domain\Queries\FilterValues;
use App\Domain\Queries\OperatorMatrix;
use App\Domain\Queries\QueryFilter;
use App\Domain\Queries\QueryPayload;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Settings\SettingValue;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Runs a TimeEntryQuery list, a criteria report, and a CSV total line.
 *
 * Filters and columns follow the time-entry query. `spent_on` uses the
 * same relative date windows as issue queries. Hours in the list and the
 * CSV follow `timespan_format`. The report groups the filtered rows.
 */
final class TimeEntryQueryRunner
{
    /**
     * @var list<string>
     */
    public const COLUMNS = [
        'spent_on',
        'user',
        'activity',
        'project',
        'issue',
        'comments',
        'hours',
        'author',
        'created_on',
        'tweek',
    ];

    /**
     * @var list<string>
     */
    public const DEFAULT_COLUMNS = [
        'spent_on',
        'user',
        'activity',
        'project',
        'issue',
        'comments',
        'hours',
    ];

    /**
     * @var list<string>
     */
    public const CRITERIA = [
        'project',
        'user',
        'activity',
        'issue',
        'tracker',
        'status',
        'version',
        'category',
    ];

    /**
     * @var list<string>
     */
    public const PERIODS = ['year', 'month', 'week', 'day'];

    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'spent_on' => 'Date',
        'user' => 'User',
        'activity' => 'Activity',
        'project' => 'Project',
        'issue' => 'Issue',
        'comments' => 'Comment',
        'hours' => 'Hours',
        'author' => 'Author',
        'created_on' => 'Created',
        'tweek' => 'Week',
    ];

    /**
     * @var array<string, string>
     */
    private const FILTER_TYPES = [
        'spent_on' => 'date',
        'user_id' => 'list',
        'author_id' => 'list',
        'activity_id' => 'list',
        'project_id' => 'list',
        'issue_id' => 'list_optional',
        'issue.tracker_id' => 'list',
        'issue.status_id' => 'list',
        'issue.fixed_version_id' => 'list_optional',
        'issue.category_id' => 'list_optional',
        'comments' => 'text',
        'hours' => 'hour',
    ];

    public function __construct(
        private readonly TimeEntryQueryScope $scope,
        private readonly SettingValue $settings,
        private readonly IssueVisibility $issues,
        private readonly CustomFieldVisibility $fields,
    ) {}

    /**
     * @param  Query|array<string, mixed>  $source
     * @return list<array<string, string>>
     */
    public function rows(User $actor, Query|array $source): array
    {
        $definition = $this->definition($actor, $source);
        $entries = $this->filtered($actor, $definition)->get();
        $rows = [];
        foreach ($entries as $entry) {
            $row = ['id' => (string) $entry->id];
            foreach ($definition['columns'] as $column) {
                $row[$column] = $this->cell($actor, $entry, $column, $definition['format']);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  Query|array<string, mixed>  $source
     * @return array{
     *     periods: list<string>,
     *     rows: list<array{key: list<string>, label: list<string>, hours: array<string, string>, total: string}>,
     *     totals: array<string, string>
     * }
     */
    public function report(User $actor, Query|array $source): array
    {
        $definition = $this->definition($actor, $source);
        $criteria = $definition['criteria'];
        $period = $definition['period'];
        if ($criteria === [] || $period === null) {
            throw new QueryValidationException('Time entry report needs criteria and a period column.');
        }

        $entries = $this->filtered($actor, $definition)->get();
        /** @var array<string, array{key: list<string>, label: list<string>, sums: array<string, float>}> $grouped */
        $grouped = [];
        /** @var array<string, float> $periodTotals */
        $periodTotals = [];
        $grand = 0.0;

        foreach ($entries as $entry) {
            $key = [];
            $label = [];
            foreach ($criteria as $name) {
                [$partKey, $partLabel] = $this->criterion($actor, $entry, $name);
                $key[] = $partKey;
                $label[] = $partLabel;
            }
            $bucket = $this->periodKey($entry, $period);
            $index = implode("\x1e", $key);
            if (! isset($grouped[$index])) {
                $grouped[$index] = ['key' => $key, 'label' => $label, 'sums' => []];
            }
            $hours = (float) $entry->hours;
            $grouped[$index]['sums'][$bucket] = ($grouped[$index]['sums'][$bucket] ?? 0.0) + $hours;
            $periodTotals[$bucket] = ($periodTotals[$bucket] ?? 0.0) + $hours;
            $grand += $hours;
        }

        $periods = [];
        foreach (array_keys($periodTotals) as $bucket) {
            $periods[] = (string) $bucket;
        }
        sort($periods, SORT_STRING);
        $format = $definition['format'];
        $rows = [];
        foreach ($grouped as $group) {
            $hours = [];
            $rowTotal = 0.0;
            foreach ($periods as $bucket) {
                $amount = $group['sums'][$bucket] ?? 0.0;
                $hours[$bucket] = HourValue::format($amount, $format);
                $rowTotal += $amount;
            }
            $rows[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'hours' => $hours,
                'total' => HourValue::format($rowTotal, $format),
            ];
        }
        usort($rows, function (array $left, array $right): int {
            $byLabel = strcasecmp(implode("\x1e", $left['label']), implode("\x1e", $right['label']));
            if ($byLabel !== 0) {
                return $byLabel;
            }

            return implode("\x1e", $left['key']) <=> implode("\x1e", $right['key']);
        });

        $totals = ['total' => HourValue::format($grand, $format)];
        foreach ($periods as $bucket) {
            $totals[$bucket] = HourValue::format($periodTotals[$bucket], $format);
        }

        return [
            'periods' => $periods,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @param  Query|array<string, mixed>  $source
     */
    public function csv(User $actor, Query|array $source): string
    {
        $definition = $this->definition($actor, $source);
        $columns = $definition['columns'];
        $lines = [implode(',', array_map(fn (string $column): string => $this->csvCell(self::HEADERS[$column]), $columns))];
        $rows = $this->rows($actor, $source);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->csvCell($row[$column]);
            }
            $lines[] = implode(',', $cells);
        }

        $sum = $this->filtered($actor, $definition)->sum('time_entries.hours');
        $total = HourValue::format((float) $sum, $definition['format']);
        $footer = [];
        foreach ($columns as $index => $column) {
            if ($column === 'hours') {
                $footer[] = $this->csvCell($total);
            } elseif ($index === 0) {
                $footer[] = $this->csvCell('Total');
            } else {
                $footer[] = '';
            }
        }
        $lines[] = implode(',', $footer);

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<string>|null  $columns
     * @param  list<array{0: string, 1: string}>|null  $sort
     * @param  array<string, mixed>|null  $options
     */
    public function assertStored(array $filters, ?array $columns, ?array $sort, ?array $options, User $actor, ?Project $project): void
    {
        foreach (QueryFilter::listFromMap($filters) as $filter) {
            $this->assertFilter($filter, $actor, $project);
        }
        foreach ($columns ?? [] as $column) {
            $this->assertColumn($column);
        }
        foreach ($sort ?? [] as [$column, $direction]) {
            $this->assertSortColumn($column);
            if ($direction !== 'asc' && $direction !== 'desc') {
                throw new QueryValidationException('Sort direction is invalid.');
            }
        }
        $this->reportOptions($options);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return Builder<TimeEntry>
     */
    private function filtered(User $actor, array $definition): Builder
    {
        $query = TimeEntry::query()->with([
            'project',
            'user',
            'author',
            'activity',
            'issue.tracker',
            'issue.status',
            'issue.category',
            'issue.fixedVersion',
            'issue.project',
        ]);
        $query = $this->scope->apply($query, $actor, $definition['project']);
        foreach ($definition['filters'] as $filter) {
            $this->applyFilter($query, $filter, $actor);
        }
        $this->applySort($query, $definition['sort']);

        return $query;
    }

    /**
     * @param  Query|array<string, mixed>  $source
     * @return array{
     *     project: ?Project,
     *     filters: list<QueryFilter>,
     *     columns: list<string>,
     *     sort: list<array{0: string, 1: string}>,
     *     criteria: list<string>,
     *     period: ?string,
     *     format: string
     * }
     */
    private function definition(User $actor, Query|array $source): array
    {
        if ($source instanceof Query) {
            $project = $source->project;
            $filters = QueryPayload::filters($source->filters);
            $columns = QueryPayload::columnNames($source->column_names);
            $sort = QueryPayload::sort($source->sort_criteria);
            $options = QueryPayload::options($source->options);
        } else {
            $project = $this->projectFrom($source['project_id'] ?? null);
            $filters = QueryPayload::filters($source['filters'] ?? []);
            $columns = QueryPayload::columnNames($source['column_names'] ?? null);
            $sort = QueryPayload::sort($source['sort_criteria'] ?? null);
            $options = QueryPayload::options($source['options'] ?? null);
        }

        $parsed = [];
        foreach (QueryFilter::listFromMap($filters) as $filter) {
            $this->assertFilter($filter, $actor, $project);
            $parsed[] = $filter;
        }
        $names = $columns ?? self::DEFAULT_COLUMNS;
        if ($names === []) {
            throw new QueryValidationException('Time entry query needs a column.');
        }
        foreach ($names as $column) {
            $this->assertColumn($column);
        }
        $order = $sort ?? [['spent_on', 'desc'], ['id', 'desc']];
        foreach ($order as [$column, $direction]) {
            $this->assertSortColumn($column);
            if ($direction !== 'asc' && $direction !== 'desc') {
                throw new QueryValidationException('Sort direction is invalid.');
            }
        }
        if (! in_array('id', array_column($order, 0), true)) {
            $order[] = ['id', 'asc'];
        }
        [$criteria, $period] = $this->reportOptions($options);

        return [
            'project' => $project,
            'filters' => $parsed,
            'columns' => $names,
            'sort' => $order,
            'criteria' => $criteria,
            'period' => $period,
            'format' => $this->settings->timespanFormat(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $options
     * @return array{0: list<string>, 1: ?string}
     */
    private function reportOptions(?array $options): array
    {
        if ($options === null || $options === []) {
            return [[], null];
        }

        $criteria = [];
        $period = null;
        foreach ($options as $key => $value) {
            if ($key === 'report_criteria') {
                if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 3) {
                    throw new QueryValidationException('Time entry report criteria are invalid.');
                }
                foreach ($value as $name) {
                    if (! is_string($name) || ! in_array($name, self::CRITERIA, true)) {
                        throw new QueryValidationException('Time entry report criterion is invalid.');
                    }
                    $criteria[] = $name;
                }
            } elseif ($key === 'report_columns') {
                if (! is_string($value) || ! in_array($value, self::PERIODS, true)) {
                    throw new QueryValidationException('Time entry report column is invalid.');
                }
                $period = $value;
            } else {
                throw new QueryValidationException('Time entry query option is invalid: '.$key.'.');
            }
        }

        return [$criteria, $period];
    }

    private function assertFilter(QueryFilter $filter, ?User $actor, ?Project $project): void
    {
        $type = self::FILTER_TYPES[$filter->field] ?? null;
        if ($type === null && preg_match('/^cf_(\d+)$/', $filter->field, $match) === 1) {
            $this->customField((int) $match[1], $actor, $project);
            $type = 'string';
        }
        if ($type === null) {
            throw new QueryValidationException('Filter field is not available: '.$filter->field.'.');
        }
        if ($filter->field === 'user_id' || $filter->field === 'author_id') {
            $type = 'list';
        }
        OperatorMatrix::assert($type, $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
    }

    private function assertColumn(string $column): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new QueryValidationException('Column is not available: '.$column.'.');
        }
    }

    private function assertSortColumn(string $column): void
    {
        if ($column === 'id' || in_array($column, self::COLUMNS, true)) {
            return;
        }

        throw new QueryValidationException('Column is not available: '.$column.'.');
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyFilter(Builder $query, QueryFilter $filter, User $actor): void
    {
        $field = $filter->field;
        $operator = $filter->operator;

        if ($field === 'spent_on') {
            $this->applySpentOn($query, $filter, $actor);

            return;
        }
        if ($field === 'hours') {
            $this->applyHours($query, $filter);

            return;
        }
        if ($field === 'comments') {
            $this->applyComments($query, $filter);

            return;
        }
        if ($field === 'user_id' || $field === 'author_id') {
            $column = $field === 'user_id' ? 'time_entries.user_id' : 'time_entries.author_id';
            $ids = FilterValues::ids($filter, $actor, true);
            if ($operator === '=') {
                $query->whereIn($column, $ids);
            } else {
                $query->whereNotIn($column, $ids);
            }

            return;
        }
        if ($field === 'activity_id' || $field === 'project_id') {
            $column = $field === 'activity_id' ? 'time_entries.activity_id' : 'time_entries.project_id';
            $ids = FilterValues::ids($filter);
            if ($operator === '=') {
                $query->whereIn($column, $ids);
            } else {
                $query->whereNotIn($column, $ids);
            }

            return;
        }
        if ($field === 'issue_id') {
            $this->applyIssueId($query, $filter);

            return;
        }
        if (str_starts_with($field, 'issue.')) {
            $this->applyIssueAttribute($query, $filter);

            return;
        }
        if (preg_match('/^cf_(\d+)$/', $field, $match) === 1) {
            $this->applyCustomField($query, (int) $match[1], $filter);
        }
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applySpentOn(Builder $query, QueryFilter $filter, User $actor): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            return;
        }
        if ($operator === '!*') {
            $query->whereNull('time_entries.spent_on');

            return;
        }
        if (in_array($operator, ['=', '>=', '<='], true)) {
            $date = FilterValues::dates($filter)[0];
            $sql = match ($operator) {
                '=' => '=',
                '>=' => '>=',
                '<=' => '<=',
            };
            $query->where('time_entries.spent_on', $sql, $date);

            return;
        }
        if ($operator === '><') {
            $dates = FilterValues::dates($filter);
            $query->whereBetween('time_entries.spent_on', [$dates[0], $dates[1]]);

            return;
        }

        $days = in_array($operator, DateWindow::OFFSET_OPERATORS, true)
            ? FilterValues::dayOffset($filter)
            : 0;
        $bound = DateWindow::forUser($actor)->calendarBound($operator, $days);
        if ($bound->empty || ($bound->from === null && $bound->to === null)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if ($bound->from !== null && $bound->to !== null) {
            $query->whereBetween('time_entries.spent_on', [$bound->from, $bound->to]);

            return;
        }
        if ($bound->from !== null) {
            $query->where('time_entries.spent_on', '>=', $bound->from);

            return;
        }
        $query->where('time_entries.spent_on', '<=', $bound->to);
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyHours(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            return;
        }
        if ($operator === '!*') {
            $query->whereNull('time_entries.hours');

            return;
        }
        $numbers = [];
        foreach (FilterValues::present($filter) as $value) {
            $numbers[] = HourValue::parse($value);
        }
        if ($operator === '><') {
            $query->whereBetween('time_entries.hours', [$numbers[0], $numbers[1]]);

            return;
        }
        $sql = match ($operator) {
            '=' => '=',
            '>=' => '>=',
            '<=' => '<=',
            default => throw new QueryValidationException('Operator '.$operator.' is not valid for hours.'),
        };
        $query->where('time_entries.hours', $sql, $numbers[0]);
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyComments(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereNotNull('time_entries.comments')->where('time_entries.comments', '!=', '');

            return;
        }
        if ($operator === '!*') {
            $query->where(function (Builder $inner): void {
                $inner->whereNull('time_entries.comments')->orWhere('time_entries.comments', '=', '');
            });

            return;
        }

        $value = FilterValues::present($filter)[0];
        if ($operator === '=') {
            $query->where('time_entries.comments', '=', $value);

            return;
        }
        if ($operator === '!') {
            $query->where(function (Builder $inner) use ($value): void {
                $inner->whereNull('time_entries.comments')->orWhere('time_entries.comments', '!=', $value);
            });

            return;
        }

        $like = '%'.$this->like($value).'%';
        if ($operator === '~' || $operator === '*~') {
            $query->where('time_entries.comments', 'like', $like);

            return;
        }
        if ($operator === '!~') {
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereNull('time_entries.comments')->orWhere('time_entries.comments', 'not like', $like);
            });

            return;
        }
        if ($operator === '^') {
            $query->where('time_entries.comments', 'like', $this->like($value).'%');

            return;
        }
        if ($operator === '$') {
            $query->where('time_entries.comments', 'like', '%'.$this->like($value));
        }
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyIssueId(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereNotNull('time_entries.issue_id');

            return;
        }
        if ($operator === '!*') {
            $query->whereNull('time_entries.issue_id');

            return;
        }
        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->whereIn('time_entries.issue_id', $ids);

            return;
        }
        $query->where(function (Builder $inner) use ($ids): void {
            $inner->whereNull('time_entries.issue_id')->orWhereNotIn('time_entries.issue_id', $ids);
        });
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyIssueAttribute(Builder $query, QueryFilter $filter): void
    {
        $column = match ($filter->field) {
            'issue.tracker_id' => 'tracker_id',
            'issue.status_id' => 'status_id',
            'issue.fixed_version_id' => 'fixed_version_id',
            'issue.category_id' => 'category_id',
            default => throw new QueryValidationException('Filter field is not available: '.$filter->field.'.'),
        };
        $operator = $filter->operator;
        if ($operator === '*') {
            $query->whereHas('issue', function (Builder $issue) use ($column): void {
                $issue->whereNotNull($column);
            });

            return;
        }
        if ($operator === '!*') {
            $query->where(function (Builder $inner) use ($column): void {
                $inner->whereNull('time_entries.issue_id')
                    ->orWhereHas('issue', function (Builder $issue) use ($column): void {
                        $issue->whereNull($column);
                    });
            });

            return;
        }
        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->whereHas('issue', function (Builder $issue) use ($column, $ids): void {
                $issue->whereIn($column, $ids);
            });

            return;
        }
        $query->where(function (Builder $inner) use ($column, $ids): void {
            $inner->whereNull('time_entries.issue_id')
                ->orWhereHas('issue', function (Builder $issue) use ($column, $ids): void {
                    $issue->whereNotIn($column, $ids);
                });
        });
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function applyCustomField(Builder $query, int $fieldId, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        $constraint = function (QueryBuilder $sub) use ($fieldId, $operator, $filter): void {
            $sub->selectRaw('1')
                ->from('custom_values')
                ->whereColumn('custom_values.customized_id', 'time_entries.id')
                ->where('custom_values.customized_type', CustomFieldTypes::TIME_ENTRY)
                ->where('custom_values.custom_field_id', $fieldId);
            if ($operator === '=' || $operator === '!') {
                $sub->whereIn('custom_values.value', FilterValues::present($filter));
            } elseif ($operator === '~' || $operator === '!~' || $operator === '*~') {
                $sub->where('custom_values.value', 'like', '%'.$this->like(FilterValues::present($filter)[0]).'%');
            }
        };
        if ($operator === '!' || $operator === '!~' || $operator === '!*') {
            $query->whereNotExists($constraint);

            return;
        }
        $query->whereExists($constraint);
    }

    /**
     * @param  Builder<TimeEntry>  $query
     * @param  list<array{0: string, 1: string}>  $sort
     */
    private function applySort(Builder $query, array $sort): void
    {
        foreach ($sort as [$column, $direction]) {
            match ($column) {
                'spent_on' => $query->orderBy('time_entries.spent_on', $direction),
                'hours' => $query->orderBy('time_entries.hours', $direction),
                'id' => $query->orderBy('time_entries.id', $direction),
                'created_on' => $query->orderBy('time_entries.created_on', $direction),
                'comments' => $query->orderBy('time_entries.comments', $direction),
                'tweek' => $query->orderBy('time_entries.tweek', $direction),
                'issue' => $query->orderBy('time_entries.issue_id', $direction),
                'user' => $this->orderByPerson($query, 'time_entries.user_id', $direction),
                'author' => $this->orderByPerson($query, 'time_entries.author_id', $direction),
                'project' => $query->orderByRaw(
                    '(SELECT projects.name FROM projects WHERE projects.id = time_entries.project_id) '.$direction,
                ),
                'activity' => $query->orderByRaw(
                    '(SELECT enumerations.name FROM enumerations WHERE enumerations.id = time_entries.activity_id) '.$direction,
                ),
                default => throw new QueryValidationException('Column is not available: '.$column.'.'),
            };
        }
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    private function orderByPerson(Builder $query, string $column, string $direction): void
    {
        $query->orderByRaw('(SELECT users.firstname FROM users WHERE users.id = '.$column.') '.$direction);
        $query->orderByRaw('(SELECT users.lastname FROM users WHERE users.id = '.$column.') '.$direction);
    }

    private function cell(User $actor, TimeEntry $entry, string $column, string $format): string
    {
        return match ($column) {
            'spent_on' => $this->date($entry),
            'user' => $this->person($entry->user),
            'author' => $this->person($entry->author),
            'activity' => $entry->activity instanceof Enumeration ? (string) $entry->activity->name : '',
            'project' => $entry->project instanceof Project ? (string) $entry->project->name : '',
            'issue' => $this->issueCell($actor, $entry->issue),
            'comments' => is_string($entry->comments) ? $entry->comments : '',
            'hours' => HourValue::format((float) $entry->hours, $format),
            'created_on' => $this->timestamp($entry->getRawOriginal('created_on')),
            'tweek' => (string) $entry->tweek,
            default => throw new QueryValidationException('Column is not available: '.$column.'.'),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function criterion(User $actor, TimeEntry $entry, string $name): array
    {
        $issue = $entry->issue;

        return match ($name) {
            'project' => [(string) $entry->project_id, $entry->project instanceof Project ? (string) $entry->project->name : ''],
            'user' => [(string) $entry->user_id, $this->person($entry->user)],
            'activity' => [(string) $entry->activity_id, $entry->activity instanceof Enumeration ? (string) $entry->activity->name : ''],
            'issue' => $issue instanceof Issue
                ? [(string) $issue->id, $this->issueCell($actor, $issue)]
                : ['', 'none'],
            'tracker' => $this->namedIssuePart($issue, $issue?->tracker?->getAttribute('name'), $issue?->tracker_id),
            'status' => $this->namedIssuePart($issue, $issue?->status?->getAttribute('name'), $issue?->status_id),
            'version' => $this->namedIssuePart($issue, $issue?->fixedVersion?->getAttribute('name'), $issue?->fixed_version_id),
            'category' => $this->namedIssuePart($issue, $issue?->category?->getAttribute('name'), $issue?->category_id),
            default => throw new QueryValidationException('Time entry report criterion is invalid.'),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function namedIssuePart(?Issue $issue, mixed $name, mixed $id): array
    {
        if (! $issue instanceof Issue || $id === null || $id === '') {
            return ['', 'none'];
        }

        return [(string) $id, is_string($name) && $name !== '' ? $name : (string) $id];
    }

    private function periodKey(TimeEntry $entry, string $period): string
    {
        return match ($period) {
            'year' => (string) $entry->tyear,
            'month' => $entry->tyear.'-'.$entry->tmonth,
            'week' => $entry->tyear.'-'.$entry->tweek,
            'day' => $this->date($entry),
            default => throw new QueryValidationException('Time entry report column is invalid.'),
        };
    }

    private function issueCell(User $actor, ?Issue $issue): string
    {
        if (! $issue instanceof Issue) {
            return '';
        }
        if (! $this->issues->canSee($actor, $issue)) {
            return '#'.$issue->id;
        }
        $tracker = $issue->tracker;
        $trackerName = $tracker !== null && $tracker->name !== ''
            ? $tracker->name
            : 'Issue';

        return $trackerName.' #'.$issue->id.': '.$issue->subject;
    }

    private function person(?User $user): string
    {
        if (! $user instanceof User) {
            return '';
        }
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);
        if ($name !== '') {
            return $name;
        }

        return (string) $user->login;
    }

    private function date(TimeEntry $entry): string
    {
        $raw = $entry->getRawOriginal('spent_on');
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        return substr($raw, 0, 10);
    }

    private function timestamp(mixed $raw): string
    {
        return is_string($raw) ? $raw : '';
    }

    private function customField(int $id, ?User $actor, ?Project $project): CustomField
    {
        $field = CustomField::query()->find($id);
        if (! $field instanceof CustomField || (string) $field->type !== 'TimeEntryCustomField' || ! $field->is_filter) {
            throw new QueryValidationException('Filter field is not available: cf_'.$id.'.');
        }
        if ($actor instanceof User && ! $this->fields->canSee($actor, $field, $project)) {
            throw new QueryValidationException('Filter field is not available: cf_'.$id.'.');
        }

        return $field;
    }

    private function projectFrom(mixed $value): ?Project
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            throw new QueryValidationException('Query project is invalid.');
        }
        $project = Project::query()->find((int) $value);
        if (! $project instanceof Project) {
            throw new QueryValidationException('Query project is unknown.');
        }

        return $project;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function csvCell(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
