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
use Illuminate\Support\Facades\DB;

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
        $customField = $this->issueField($this->fieldId($filter->field), $filter->field, $actor, $project);
        $format = $this->formats->get((string) $customField->field_format);
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
        $formatKey = $format->key();
        $callback = function (QueryBuilder $sub) use ($customField, $filter, $filterType, $positive, $dates, $actor, $formatKey): void {
            $sub->selectRaw('1')
                ->from('custom_values')
                ->where('custom_values.customized_type', 'Issue')
                ->whereColumn('custom_values.customized_id', 'issues.id')
                ->where('custom_values.custom_field_id', $customField->id);
            $this->predicate($sub, $filter, $filterType, $positive, $dates, $actor, $formatKey);
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

    /**
     * @param  Builder<Issue>  $query
     */
    public function applyChained(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        if (preg_match('/^cf_(\d+)\.(due_date|status)$/', $filter->field, $matches) !== 1) {
            throw new QueryValidationException('Chained custom field filter is not supported: '.$filter->field.'.');
        }

        $customField = $this->issueField((int) $matches[1], $filter->field, $actor, $project);
        if ($customField->field_format !== 'version') {
            throw new QueryValidationException('Chained custom field filters require a version field: '.$filter->field.'.');
        }

        $suffix = $matches[2];
        if ($suffix === 'status') {
            $this->versionStatus($query, $customField->id, $filter);

            return;
        }

        $this->versionDueDate($query, $customField->id, $filter, $dates);
    }

    private function issueField(int $fieldId, string $label, ?User $actor, ?Project $project): CustomField
    {
        $customField = CustomField::query()->with('roles')->find($fieldId);
        if (! $customField instanceof CustomField || $customField->type !== 'IssueCustomField') {
            throw new QueryValidationException('Custom field filter is unknown: '.$label.'.');
        }
        if (! $customField->is_filter) {
            throw new QueryValidationException('Custom field is not a filter: '.$label.'.');
        }

        $format = $this->formats->get((string) $customField->field_format);
        if (! $format->isImplemented()) {
            throw new QueryValidationException('Custom field format cannot be filtered yet: '.$customField->field_format.'.');
        }
        if (! $this->visibility->canSee($actor, $customField, $project)) {
            throw new QueryValidationException('Custom field is not visible: '.$label.'.');
        }

        return $customField;
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function versionStatus(Builder $query, int $fieldId, QueryFilter $filter): void
    {
        OperatorMatrix::assert('list', $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
        $values = FilterValues::present($filter);
        if (in_array('me', $values, true)) {
            throw new QueryValidationException('Filter value me is not valid for '.$filter->field.'.');
        }

        $callback = function (QueryBuilder $sub) use ($fieldId, $values): void {
            $this->versionBase($sub, $fieldId);
            $sub->whereIn('versions.status', $values);
        };

        if ($filter->operator === '!') {
            $query->whereNotExists($callback);

            return;
        }

        if ($filter->operator !== '=') {
            throw new QueryValidationException('Operator '.$filter->operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereExists($callback);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function versionDueDate(Builder $query, int $fieldId, QueryFilter $filter, DateWindow $dates): void
    {
        OperatorMatrix::assert('date', $filter->operator, $filter->field);
        FilterValues::assertCount($filter);
        $operator = $filter->operator;

        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub) use ($fieldId): void {
                $this->versionBase($sub, $fieldId);
                $sub->whereNotNull('versions.effective_date');
            });

            return;
        }

        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub) use ($fieldId): void {
                $this->versionBase($sub, $fieldId);
                $sub->whereNotNull('versions.effective_date');
            });

            return;
        }

        $query->whereExists(function (QueryBuilder $sub) use ($fieldId, $filter, $operator, $dates): void {
            $this->versionBase($sub, $fieldId);
            $this->calendarColumn($sub, 'versions.effective_date', $filter, $operator, $dates);
        });
    }

    private function versionBase(QueryBuilder $sub, int $fieldId): void
    {
        $sub->selectRaw('1')
            ->from('custom_values')
            ->join(
                'versions',
                'versions.id',
                '=',
                DB::raw('CAST(custom_values.value AS UNSIGNED)'),
            )
            ->where('custom_values.customized_type', 'Issue')
            ->whereColumn('custom_values.customized_id', 'issues.id')
            ->where('custom_values.custom_field_id', $fieldId)
            ->whereRaw("custom_values.value REGEXP '^[0-9]+$'");
    }

    private function predicate(QueryBuilder $sub, QueryFilter $filter, string $filterType, string $operator, DateWindow $dates, ?User $actor, string $formatKey): void
    {
        if ($operator === '*') {
            $this->nonBlank($sub);

            return;
        }

        if (in_array($filterType, ['list', 'list_optional', 'list_with_history', 'list_optional_with_history', 'list_status'], true)) {
            $this->listEquals($sub, $filter, $formatKey, $actor);

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

    private function listEquals(QueryBuilder $sub, QueryFilter $filter, string $formatKey, ?User $actor): void
    {
        if ($formatKey === 'user') {
            $values = [];
            foreach (FilterValues::ids($filter, $actor, true) as $id) {
                $values[] = (string) $id;
            }
            $sub->whereIn('custom_values.value', $values);

            return;
        }

        $values = FilterValues::present($filter);
        if (in_array('me', $values, true)) {
            throw new QueryValidationException('Filter value me is not valid for '.$filter->field.'.');
        }

        $sub->whereIn('custom_values.value', $values);
    }

    private function text(QueryBuilder $sub, QueryFilter $filter, string $operator): void
    {
        if ($operator === '=') {
            $sub->whereIn('custom_values.value', FilterValues::present($filter));

            return;
        }

        if ($operator === '^' || $operator === '$') {
            $values = FilterValues::present($filter);
            $sub->where(function (QueryBuilder $inner) use ($values, $operator): void {
                foreach ($values as $index => $value) {
                    $pattern = $operator === '^' ? FilterValues::likePrefix($value) : FilterValues::likeSuffix($value);
                    $sql = 'LOWER(custom_values.value) LIKE ? ESCAPE ?';
                    $bindings = [$pattern, '\\'];
                    if ($index === 0) {
                        $inner->whereRaw($sql, $bindings);
                    } else {
                        $inner->orWhereRaw($sql, $bindings);
                    }
                }
            });

            return;
        }

        $tokens = FilterValues::tokens($filter);
        if ($operator === '~') {
            foreach ($tokens as $token) {
                $sub->whereRaw('LOWER(custom_values.value) LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
            }

            return;
        }

        if ($operator !== '*~') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $sub->where(function (QueryBuilder $inner) use ($tokens): void {
            foreach ($tokens as $index => $token) {
                $sql = 'LOWER(custom_values.value) LIKE ? ESCAPE ?';
                $bindings = [FilterValues::like($token), '\\'];
                if ($index === 0) {
                    $inner->whereRaw($sql, $bindings);
                } else {
                    $inner->orWhereRaw($sql, $bindings);
                }
            }
        });
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
        $this->calendarColumn($sub, 'custom_values.value', $filter, $operator, $dates);
    }

    private function calendarColumn(QueryBuilder $sub, string $column, QueryFilter $filter, string $operator, DateWindow $dates): void
    {
        if (in_array($operator, DateWindow::CLOSED_RELATIVE, true) || in_array($operator, DateWindow::OFFSET_OPERATORS, true)) {
            $days = in_array($operator, DateWindow::OFFSET_OPERATORS, true) ? FilterValues::dayOffset($filter) : 0;
            $dates->calendarBound($operator, $days)->apply($sub, $column);

            return;
        }

        $values = FilterValues::dates($filter);
        if ($operator === '=') {
            $sub->whereIn($column, $values);

            return;
        }

        if ($operator === '>=') {
            $sub->where($column, '>=', $values[0]);

            return;
        }

        if ($operator === '<=') {
            $sub->where($column, '<=', $values[0]);

            return;
        }

        if ($operator === '><') {
            $sub->whereBetween($column, [$values[0], $values[1]]);

            return;
        }

        throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
    }
}
