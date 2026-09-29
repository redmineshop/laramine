<?php

namespace App\Domain\Queries;

use App\Domain\CustomFields\CustomFieldVisibility;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Compiles `cf_N` filters as EXISTS / NOT EXISTS on `custom_values`.
 *
 * `!`, `!~`, and `!*` use NOT EXISTS, so a missing value matches "not equal" and "none".
 * List `=` matches when any stored value is in the list.
 */
final class CustomFieldFilterSql
{
    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldVisibility $visibility,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        $fieldId = $this->fieldId($filter->field);
        $customField = CustomField::query()->with('roles')->find($fieldId);
        if (! $customField instanceof CustomField || $customField->type !== 'IssueCustomField') {
            throw new QueryValidationException('Custom field filter is unknown: '.$filter->field.'.');
        }
        if (! $customField->is_filter) {
            throw new QueryValidationException('Custom field is not a filter: '.$filter->field.'.');
        }

        $format = $this->formats->get((string) $customField->field_format);
        if (! $format->isImplemented()) {
            throw new QueryValidationException('Custom field format cannot be filtered yet: '.$customField->field_format.'.');
        }
        if (! $this->visibility->canSee($actor, $customField, $project)) {
            throw new QueryValidationException('Custom field is not visible: '.$filter->field.'.');
        }

        $filterType = $format->queryFilterType();
        OperatorMatrix::assert($filterType, $filter->operator, $filter->field);
        FilterValues::assertCount($filter);

        $negative = in_array($filter->operator, ['!', '!~', '!*'], true);
        $positive = match ($filter->operator) {
            '!' => '=',
            '!~' => '~',
            '!*' => '*',
            default => $filter->operator,
        };
        $callback = function (QueryBuilder $sub) use ($customField, $filter, $filterType, $positive, $dates): void {
            $sub->selectRaw('1')
                ->from('custom_values')
                ->where('custom_values.customized_type', 'Issue')
                ->whereColumn('custom_values.customized_id', 'issues.id')
                ->where('custom_values.custom_field_id', $customField->id);
            $this->predicate($sub, $filter, $filterType, $positive, $dates);
        };

        if ($negative) {
            $query->whereNotExists($callback);

            return;
        }

        $query->whereExists($callback);
    }

    private function fieldId(string $field): int
    {
        if (preg_match('/^cf_(\d+)$/', $field, $matches) !== 1) {
            throw new QueryValidationException('Custom field filter is unknown: '.$field.'.');
        }

        return (int) $matches[1];
    }

    private function predicate(QueryBuilder $sub, QueryFilter $filter, string $filterType, string $operator, DateWindow $dates): void
    {
        if ($operator === '*') {
            $this->nonBlank($sub);

            return;
        }

        if (in_array($filterType, ['list', 'list_optional', 'list_with_history', 'list_optional_with_history', 'list_status'], true)) {
            $this->listEquals($sub, $filter);

            return;
        }

        if ($filterType === 'string' || $filterType === 'text') {
            $this->text($sub, $filter, $operator);

            return;
        }

        if (in_array($filterType, ['integer', 'float', 'hour'], true)) {
            $this->number($sub, $filter, $operator, $filterType === 'integer');

            return;
        }

        if ($filterType === 'date' || $filterType === 'date_past') {
            $this->date($sub, $filter, $operator, $dates);

            return;
        }

        throw new QueryValidationException('Custom field filter type is not supported: '.$filterType.'.');
    }

    private function nonBlank(QueryBuilder $sub): void
    {
        $sub->whereNotNull('custom_values.value')
            ->where('custom_values.value', '!=', '');
    }

    private function listEquals(QueryBuilder $sub, QueryFilter $filter): void
    {
        $sub->whereIn('custom_values.value', FilterValues::present($filter));
    }

    private function text(QueryBuilder $sub, QueryFilter $filter, string $operator): void
    {
        if ($operator === '=') {
            $sub->whereIn('custom_values.value', FilterValues::present($filter));

            return;
        }

        if ($operator !== '~') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        foreach (FilterValues::tokens($filter) as $token) {
            $sub->whereRaw('LOWER(custom_values.value) LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
        }
    }

    private function number(QueryBuilder $sub, QueryFilter $filter, string $operator, bool $integer): void
    {
        $pattern = $integer ? '^[+-]?[0-9]+$' : '^[+-]?[0-9]+(\\.[0-9]+)?$';
        $numbers = $integer ? FilterValues::integers($filter) : FilterValues::decimals($filter);
        $sub->whereRaw('custom_values.value REGEXP ?', [$pattern]);

        if ($operator === '=') {
            $sub->where(function (QueryBuilder $inner) use ($numbers): void {
                foreach ($numbers as $index => $number) {
                    $sql = 'CAST(custom_values.value AS DECIMAL(30, 10)) = ?';
                    if ($index === 0) {
                        $inner->whereRaw($sql, [$number]);
                    } else {
                        $inner->orWhereRaw($sql, [$number]);
                    }
                }
            });

            return;
        }

        $bound = $numbers[0];
        $sql = match ($operator) {
            '>=' => 'CAST(custom_values.value AS DECIMAL(30, 10)) >= ?',
            '<=' => 'CAST(custom_values.value AS DECIMAL(30, 10)) <= ?',
            '><' => 'CAST(custom_values.value AS DECIMAL(30, 10)) BETWEEN ? AND ?',
            default => throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.'),
        };

        if ($operator === '><') {
            $sub->whereRaw($sql, [$numbers[0], $numbers[1]]);

            return;
        }

        $sub->whereRaw($sql, [$bound]);
    }

    private function date(QueryBuilder $sub, QueryFilter $filter, string $operator, DateWindow $dates): void
    {
        $sub->whereRaw("custom_values.value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'");

        if (in_array($operator, ['t', 'ld', 'w', 'lw', 'm', 'lm', 'y'], true)) {
            [$from, $to] = $dates->dates($operator);
            $sub->whereBetween('custom_values.value', [$from, $to]);

            return;
        }

        $values = FilterValues::dates($filter);
        if ($operator === '=') {
            $sub->whereIn('custom_values.value', $values);

            return;
        }

        if ($operator === '>=') {
            $sub->where('custom_values.value', '>=', $values[0]);

            return;
        }

        if ($operator === '<=') {
            $sub->where('custom_values.value', '<=', $values[0]);

            return;
        }

        if ($operator === '><') {
            $sub->whereBetween('custom_values.value', [$values[0], $values[1]]);

            return;
        }

        throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
    }
}
