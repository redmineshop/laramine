<?php

namespace App\Domain\Queries;

use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sums `options.totalable_names` over an already scoped issue query.
 *
 * Built-in names are `estimated_hours` and `spent_hours`. `cf_{id}` is accepted
 * for an issue custom field the actor can see when the format supports totals
 * (int and float). A name does not have to appear in `column_names`, and
 * `is_filter` is not required. `display_type` is ignored here.
 */
final class IssueQueryTotals
{
    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldVisibility $visibility,
    ) {}

    /**
     * @param  array<string, mixed>|null  $options
     * @return list<QueryTotalColumn>
     */
    public function columns(?array $options, ?User $actor, ?Project $project): array
    {
        if ($options === null || ! array_key_exists('totalable_names', $options)) {
            return [];
        }

        $raw = $options['totalable_names'];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new QueryValidationException('Query totals must be a list.');
        }

        $columns = [];
        $seen = [];
        foreach ($raw as $item) {
            if (! is_string($item) || $item === '') {
                throw new QueryValidationException('Query totals must be a list of column names.');
            }
            if (isset($seen[$item])) {
                throw new QueryValidationException('Total column is repeated: '.$item.'.');
            }
            $seen[$item] = true;
            $columns[] = $this->column($item, $actor, $project);
        }

        return $columns;
    }

    /**
     * @param  Builder<Issue>  $issues
     * @param  list<QueryTotalColumn>  $columns
     * @return array<string, string>
     */
    public function sum(Builder $issues, array $columns): array
    {
        $totals = [];
        foreach ($columns as $column) {
            $totals[$column->name] = match ($column->source) {
                QueryTotalSource::EstimatedHours => $this->estimatedHours($issues),
                QueryTotalSource::SpentHours => $this->spentHours($issues),
                QueryTotalSource::CustomInt => $this->customSum($issues, $column, true),
                QueryTotalSource::CustomFloat => $this->customSum($issues, $column, false),
            };
        }

        return $totals;
    }

    private function column(string $name, ?User $actor, ?Project $project): QueryTotalColumn
    {
        if ($name === QueryTotalSource::EstimatedHours->value) {
            return new QueryTotalColumn($name, QueryTotalSource::EstimatedHours, null);
        }

        if ($name === QueryTotalSource::SpentHours->value) {
            return new QueryTotalColumn($name, QueryTotalSource::SpentHours, null);
        }

        if (preg_match('/^cf_(\d+)$/', $name, $matches) !== 1) {
            throw new QueryValidationException('Total column is not available: '.$name.'.');
        }

        $customField = CustomField::query()->with('roles')->find((int) $matches[1]);
        if (! $customField instanceof CustomField || $customField->type !== 'IssueCustomField') {
            throw new QueryValidationException('Custom field total is unknown: '.$name.'.');
        }
        if (! $this->visibility->canSee($actor, $customField, $project)) {
            throw new QueryValidationException('Custom field is not visible: '.$name.'.');
        }

        $format = $this->formats->get((string) $customField->field_format);
        if (! $format->supportsTotal()) {
            throw new QueryValidationException('Custom field is not totalable: '.$name.'.');
        }

        $source = $format->key() === 'int'
            ? QueryTotalSource::CustomInt
            : QueryTotalSource::CustomFloat;

        return new QueryTotalColumn($name, $source, (int) $customField->id);
    }

    /**
     * @param  Builder<Issue>  $issues
     */
    private function estimatedHours(Builder $issues): string
    {
        $total = DB::table('issues')
            ->whereIn('issues.id', $this->scopedIds($issues))
            ->selectRaw('COALESCE(ROUND(SUM(CAST(issues.estimated_hours AS DECIMAL(30,4))), 2), 0) as total')
            ->value('total');

        return $this->plainDecimal($total);
    }

    /**
     * Each issue uses the same rounded sum as the `spent_time` filter.
     * The query total adds those per-issue numbers.
     *
     * @param  Builder<Issue>  $issues
     */
    private function spentHours(Builder $issues): string
    {
        $perIssue = DB::table('issues')
            ->whereIn('issues.id', $this->scopedIds($issues))
            ->leftJoin('time_entries', 'time_entries.issue_id', '=', 'issues.id')
            ->groupBy('issues.id')
            ->selectRaw('COALESCE(ROUND(CAST(SUM(time_entries.hours) AS DECIMAL(30,3)), 2), 0) as per_issue');

        $total = DB::query()
            ->fromSub($perIssue, 'spent_totals')
            ->selectRaw('COALESCE(SUM(per_issue), 0) as total')
            ->value('total');

        return $this->plainDecimal($total);
    }

    /**
     * @param  Builder<Issue>  $issues
     */
    private function customSum(Builder $issues, QueryTotalColumn $column, bool $integer): string
    {
        $fieldId = $column->customFieldId;
        if ($fieldId === null) {
            throw new QueryValidationException('Custom field total is unknown: '.$column->name.'.');
        }

        $pattern = $integer
            ? '^[+-]?[0-9]+$'
            : '^[+-]?([0-9]+(\\.[0-9]+)?|\\.[0-9]+)$';
        $expression = $integer
            ? 'COALESCE(SUM(CAST(custom_values.value AS DECIMAL(30,0))), 0)'
            : 'COALESCE(SUM(CAST(custom_values.value AS DECIMAL(30,10))), 0)';

        $total = DB::table('custom_values')
            ->where('custom_values.customized_type', 'Issue')
            ->where('custom_values.custom_field_id', $fieldId)
            ->whereIn('custom_values.customized_id', $this->scopedIds($issues))
            ->whereRaw('custom_values.value REGEXP ?', [$pattern])
            ->selectRaw($expression.' as total')
            ->value('total');

        return $this->plainDecimal($total);
    }

    /**
     * @param  Builder<Issue>  $issues
     * @return Builder<Issue>
     */
    private function scopedIds(Builder $issues): Builder
    {
        $scoped = clone $issues;

        return $scoped->reorder()->select('issues.id')->distinct();
    }

    private function plainDecimal(mixed $raw): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            if (! is_finite($raw)) {
                return '0';
            }

            $raw = rtrim(rtrim(sprintf('%.10F', $raw), '0'), '.');
        }

        if (! is_string($raw)) {
            return '0';
        }

        $text = trim($raw);
        if ($text === '' || preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $text) !== 1) {
            return '0';
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        if (str_starts_with($text, '.')) {
            $text = '0'.$text;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = rtrim($fraction, '0');
        $body = $fraction === '' ? $whole : $whole.'.'.$fraction;
        if ($body === '0') {
            return '0';
        }

        return $negative ? '-'.$body : $body;
    }
}
