<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * AND-combines issue filters onto an issue query.
 *
 * `o` and `c` ignore their values. Text `~` requires every whitespace token.
 * `*~` ORs those tokens. `^` and `$` use the whole value.
 */
final class IssueQueryCompiler
{
    public function __construct(
        private readonly CustomFieldFilterSql $customFields,
        private readonly HistoryFilterSql $history,
        private readonly RelationFilterSql $relations,
    ) {}

    /**
     * @param  Builder<Issue>  $query
     * @param  list<QueryFilter>  $filters
     */
    public function apply(Builder $query, array $filters, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        foreach ($filters as $filter) {
            $this->applyOne($query, $filter, $actor, $project, $dates);
        }
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function applyOne(Builder $query, QueryFilter $filter, ?User $actor, ?Project $project, DateWindow $dates): void
    {
        if (preg_match('/^cf_\d+\.(?:due_date|status)$/', $filter->field) === 1) {
            $this->customFields->applyChained($query, $filter, $actor, $project, $dates);

            return;
        }

        DeferredIssueFilters::assert($filter->field);

        if (preg_match('/^cf_\d+$/', $filter->field) === 1) {
            $this->customFields->apply($query, $filter, $actor, $project, $dates);

            return;
        }

        $field = IssueFilterCatalog::find($filter->field);
        if ($field === null) {
            throw new QueryValidationException('Unknown filter field: '.$filter->field.'.');
        }

        OperatorMatrix::assert($field->filterType, $filter->operator, $filter->field);
        FilterValues::assertCount($filter);

        match ($field->kind) {
            IssueFilterKind::Status => $this->status($query, $filter, $actor),
            IssueFilterKind::List => $this->list($query, $field, $filter, false, $actor),
            IssueFilterKind::Optional => $this->list($query, $field, $filter, true, $actor),
            IssueFilterKind::Text => $this->text($query, $field, $filter),
            IssueFilterKind::Date => $this->calendarDate($query, $field, $filter, $dates),
            IssueFilterKind::DateTime => $this->dateTime($query, $field, $filter, $dates),
            IssueFilterKind::Integer => $this->number($query, $field, $filter, true),
            IssueFilterKind::Float => $this->number($query, $field, $filter, false),
            IssueFilterKind::Bool => $this->boolFlag($query, $field, $filter),
            IssueFilterKind::Parent => $this->parent($query, $field, $filter),
            IssueFilterKind::Child => $this->child($query, $filter),
            IssueFilterKind::Relation => $this->relations->apply($query, $field, $filter),
        };
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function status(Builder $query, QueryFilter $filter, ?User $actor): void
    {
        $operator = $filter->operator;
        if ($operator === 'o' || $operator === 'c') {
            $closed = $operator === 'c';
            $query->whereIn('issues.status_id', function (QueryBuilder $sub) use ($closed): void {
                $sub->select('id')->from('issue_statuses')->where('is_closed', $closed);
            });

            return;
        }

        if ($operator === '*') {
            $query->whereNotNull('issues.status_id');

            return;
        }

        if (in_array($operator, ['ev', '!ev', 'cf'], true)) {
            $this->history->apply($query, 'status_id', $operator, $this->historyValues($filter, $actor, false));

            return;
        }

        $ids = FilterValues::ids($filter);
        if ($operator === '=') {
            $query->whereIn('issues.status_id', $ids);

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($ids): void {
            /** @var Builder<Issue> $inner */
            $inner->whereNotIn('issues.status_id', $ids)->orWhereNull('issues.status_id');
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function list(Builder $query, IssueField $field, QueryFilter $filter, bool $optional, ?User $actor): void
    {
        $column = $field->sql();
        $operator = $filter->operator;
        $allowMe = $field->name === 'author_id' || $field->name === 'assigned_to_id';

        if ($operator === '*') {
            $query->whereNotNull($column);

            return;
        }

        if ($operator === '!*') {
            $query->whereNull($column);

            return;
        }

        if (in_array($operator, ['ev', '!ev', 'cf'], true)) {
            $this->history->apply($query, $field->column, $operator, $this->historyValues($filter, $actor, $allowMe));

            return;
        }

        $ids = FilterValues::ids($filter, $actor, $allowMe);
        if ($operator === '=') {
            $query->whereIn($column, $ids);

            return;
        }

        if ($operator !== '!') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($column, $ids, $optional): void {
            /** @var Builder<Issue> $inner */
            $inner->whereNotIn($column, $ids);
            if ($optional) {
                $inner->orWhereNull($column);
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function text(Builder $query, IssueField $field, QueryFilter $filter): void
    {
        $column = $field->sql();
        $operator = $filter->operator;

        if ($operator === '*') {
            $query->whereNotNull($column)->where($column, '!=', '');

            return;
        }

        if ($operator === '!*') {
            $query->where(function (Builder $inner) use ($column): void {
                /** @var Builder<Issue> $inner */
                $inner->whereNull($column)->orWhere($column, '');
            });

            return;
        }

        if ($operator === '=') {
            $query->whereIn($column, FilterValues::present($filter));

            return;
        }

        if ($operator === '^' || $operator === '$') {
            $this->edges($query, $column, $filter, $operator === '^');

            return;
        }

        $tokens = FilterValues::tokens($filter);
        if ($operator === '~') {
            foreach ($tokens as $token) {
                $query->whereRaw('LOWER('.$column.') LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
            }

            return;
        }

        if ($operator === '*~') {
            $query->where(function (Builder $inner) use ($column, $tokens): void {
                foreach ($tokens as $index => $token) {
                    $sql = 'LOWER('.$column.') LIKE ? ESCAPE ?';
                    $bindings = [FilterValues::like($token), '\\'];
                    if ($index === 0) {
                        $inner->whereRaw($sql, $bindings);
                    } else {
                        $inner->orWhereRaw($sql, $bindings);
                    }
                }
            });

            return;
        }

        if ($operator !== '!~') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->where(function (Builder $inner) use ($column, $tokens): void {
            /** @var Builder<Issue> $inner */
            $inner->whereNull($column)->orWhere($column, '');
            $inner->orWhere(function (Builder $missing) use ($column, $tokens): void {
                foreach ($tokens as $index => $token) {
                    $sql = 'LOWER('.$column.') NOT LIKE ? ESCAPE ?';
                    $bindings = [FilterValues::like($token), '\\'];
                    if ($index === 0) {
                        $missing->whereRaw($sql, $bindings);
                    } else {
                        $missing->orWhereRaw($sql, $bindings);
                    }
                }
            });
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function calendarDate(Builder $query, IssueField $field, QueryFilter $filter, DateWindow $dates): void
    {
        $column = $field->sql();
        $operator = $filter->operator;

        if ($operator === '*') {
            $query->whereNotNull($column);

            return;
        }

        if ($operator === '!*') {
            $query->whereNull($column);

            return;
        }

        if ($this->isRelative($operator)) {
            $dates->calendarBound($operator, $this->offsetDays($filter, $operator))->apply($query, $column);

            return;
        }

        $values = FilterValues::dates($filter);
        if ($operator === '=') {
            $query->whereIn($column, $values);

            return;
        }

        if ($operator === '>=') {
            $query->where($column, '>=', $values[0]);

            return;
        }

        if ($operator === '<=') {
            $query->where($column, '<=', $values[0]);

            return;
        }

        if ($operator !== '><') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereBetween($column, [$values[0], $values[1]]);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function dateTime(Builder $query, IssueField $field, QueryFilter $filter, DateWindow $dates): void
    {
        $column = $field->sql();
        $operator = $filter->operator;

        if ($operator === '*') {
            $query->whereNotNull($column);

            return;
        }

        if ($operator === '!*') {
            $query->whereNull($column);

            return;
        }

        if ($this->isRelative($operator)) {
            $dates->dateTimeBound($operator, $this->offsetDays($filter, $operator))->apply($query, $column);

            return;
        }

        $values = FilterValues::dates($filter);
        if ($operator === '=') {
            [$from, $to] = $dates->explicitDatetimes($values[0], $values[0]);
            $query->whereBetween($column, [$from, $to]);

            return;
        }

        if ($operator === '>=') {
            [$from] = $dates->explicitDatetimes($values[0], $values[0]);
            $query->where($column, '>=', $from);

            return;
        }

        if ($operator === '<=') {
            [, $to] = $dates->explicitDatetimes($values[0], $values[0]);
            $query->where($column, '<=', $to);

            return;
        }

        if ($operator !== '><') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        [$from, $to] = $dates->explicitDatetimes($values[0], $values[1]);
        $query->whereBetween($column, [$from, $to]);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function number(Builder $query, IssueField $field, QueryFilter $filter, bool $integer): void
    {
        $column = $field->sql();
        $operator = $filter->operator;

        if ($operator === '*') {
            $query->whereNotNull($column);

            return;
        }

        if ($operator === '!*') {
            $query->whereNull($column);

            return;
        }

        $numbers = $integer ? FilterValues::integers($filter) : FilterValues::decimals($filter);
        if ($operator === '=') {
            $query->whereIn($column, $numbers);

            return;
        }

        if ($operator === '!') {
            $query->where(function (Builder $inner) use ($column, $numbers): void {
                /** @var Builder<Issue> $inner */
                $inner->whereNotIn($column, $numbers)->orWhereNull($column);
            });

            return;
        }

        if ($operator === '>=') {
            $query->where($column, '>=', $numbers[0]);

            return;
        }

        if ($operator === '<=') {
            $query->where($column, '<=', $numbers[0]);

            return;
        }

        $query->whereBetween($column, [$numbers[0], $numbers[1]]);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function boolFlag(Builder $query, IssueField $field, QueryFilter $filter): void
    {
        $flags = FilterValues::flags($filter);
        if ($filter->operator === '=') {
            $query->whereIn($field->sql(), $flags);

            return;
        }

        $query->whereNotIn($field->sql(), $flags);
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function parent(Builder $query, IssueField $field, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '!*') {
            $query->whereNull($field->sql());

            return;
        }

        if ($operator === '*') {
            $query->whereNotNull($field->sql());

            return;
        }

        if ($operator === '~') {
            $this->subjectExists($query, 'parent_issues', 'parent_issues.id', 'issues.parent_id', $filter);

            return;
        }

        if ($operator !== '=') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $query->whereIn($field->sql(), FilterValues::ids($filter));
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function child(Builder $query, QueryFilter $filter): void
    {
        $operator = $filter->operator;
        if ($operator === '!*') {
            $query->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('issues as child_issues')
                    ->whereColumn('child_issues.parent_id', 'issues.id');
            });

            return;
        }

        if ($operator === '*') {
            $query->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('issues as child_issues')
                    ->whereColumn('child_issues.parent_id', 'issues.id');
            });

            return;
        }

        if ($operator === '~') {
            $this->subjectExists($query, 'child_issues', 'child_issues.parent_id', 'issues.id', $filter);

            return;
        }

        if ($operator !== '=') {
            throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
        }

        $ids = FilterValues::ids($filter);
        $query->whereIn('issues.id', function (QueryBuilder $sub) use ($ids): void {
            $sub->select('parent_id')
                ->from('issues as child_issues')
                ->whereIn('child_issues.id', $ids)
                ->whereNotNull('child_issues.parent_id');
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function edges(Builder $query, string $column, QueryFilter $filter, bool $prefix): void
    {
        $values = FilterValues::present($filter);
        $query->where(function (Builder $inner) use ($column, $values, $prefix): void {
            foreach ($values as $index => $value) {
                $pattern = $prefix ? FilterValues::likePrefix($value) : FilterValues::likeSuffix($value);
                $sql = 'LOWER('.$column.') LIKE ? ESCAPE ?';
                $bindings = [$pattern, '\\'];
                if ($index === 0) {
                    $inner->whereRaw($sql, $bindings);
                } else {
                    $inner->orWhereRaw($sql, $bindings);
                }
            }
        });
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function subjectExists(Builder $query, string $alias, string $left, string $right, QueryFilter $filter): void
    {
        $tokens = FilterValues::tokens($filter);
        $query->whereExists(function (QueryBuilder $sub) use ($alias, $left, $right, $tokens): void {
            $sub->selectRaw('1')
                ->from('issues as '.$alias)
                ->whereColumn($left, $right);
            foreach ($tokens as $token) {
                $sub->whereRaw('LOWER('.$alias.'.subject) LIKE ? ESCAPE ?', [FilterValues::like($token), '\\']);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function historyValues(QueryFilter $filter, ?User $actor, bool $allowMe): array
    {
        $values = [];
        foreach (FilterValues::ids($filter, $actor, $allowMe) as $id) {
            $values[] = (string) $id;
        }

        return $values;
    }

    private function isRelative(string $operator): bool
    {
        return in_array($operator, DateWindow::CLOSED_RELATIVE, true)
            || in_array($operator, DateWindow::OFFSET_OPERATORS, true);
    }

    private function offsetDays(QueryFilter $filter, string $operator): int
    {
        if (! in_array($operator, DateWindow::OFFSET_OPERATORS, true)) {
            return 0;
        }

        return FilterValues::dayOffset($filter);
    }
}
