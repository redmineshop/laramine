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
 * Built-in names are `estimated_hours`, `estimated_remaining_hours`, and `spent_hours`. `cf_{id}` is accepted
 * for an issue custom field the actor can see when the format supports totals
 * (int and float). A name does not have to appear in `column_names`, and
 * `is_filter` is not required. `display_type` does not change the sums.
 * `spent_hours` follows `time_entries_visibility` per issue project.
 */
final class IssueQueryTotals
{
    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldVisibility $visibility,
        private readonly SpentHoursQuery $spentHours,
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
    public function sum(Builder $issues, array $columns, ?User $actor): array
    {
        $totals = [];
        foreach ($columns as $column) {
            $totals[$column->name] = match ($column->source) {
                QueryTotalSource::EstimatedHours => $this->estimatedHours($issues),
                QueryTotalSource::EstimatedRemainingHours => $this->estimatedRemainingHours($issues),
                QueryTotalSource::SpentHours => $this->spentHours->total($issues, $actor),
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

        if ($name === QueryTotalSource::EstimatedRemainingHours->value) {
            return new QueryTotalColumn($name, QueryTotalSource::EstimatedRemainingHours, null);
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

        return PlainDecimal::text($total);
    }

    /**
     * @param  Builder<Issue>  $issues
     */
    private function estimatedRemainingHours(Builder $issues): string
    {
        $total = DB::table('issues')
            ->whereIn('issues.id', $this->scopedIds($issues))
            ->selectRaw('COALESCE(ROUND(SUM('.IssueQueryColumns::REMAINING_SQL.'), 2), 0) as total')
            ->value('total');

        return PlainDecimal::text($total);
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

        return PlainDecimal::text($total);
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
}
