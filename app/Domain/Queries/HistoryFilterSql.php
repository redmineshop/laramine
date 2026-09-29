<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * History operators read `journals` / `journal_details` for core attribute changes.
 *
 * `property` is `attr` and `prop_key` is the issue column (`status_id`, …).
 * Compared values are decimal id strings. `ev` matches the current column or
 * either journal side. `cf` matches `old_value` only. `!ev` matches neither.
 */
final class HistoryFilterSql
{
    /**
     * @param  Builder<Issue>  $query
     * @param  list<string>  $values
     */
    public function apply(Builder $query, string $column, string $operator, array $values): void
    {
        if ($operator === 'cf') {
            $query->whereExists(function (QueryBuilder $sub) use ($column, $values): void {
                $this->history($sub, $column, $values, true);
            });

            return;
        }

        if ($operator === 'ev') {
            $query->where(function (Builder $inner) use ($column, $values): void {
                /** @var Builder<Issue> $inner */
                $inner->whereIn('issues.'.$column, $values)
                    ->orWhereExists(function (QueryBuilder $sub) use ($column, $values): void {
                        $this->history($sub, $column, $values, false);
                    });
            });

            return;
        }

        if ($operator !== '!ev') {
            throw new QueryValidationException('Operator '.$operator.' is not a history operator.');
        }

        $query->where(function (Builder $inner) use ($column, $values): void {
            /** @var Builder<Issue> $inner */
            $inner->where(function (Builder $current) use ($column, $values): void {
                /** @var Builder<Issue> $current */
                $current->whereNotIn('issues.'.$column, $values)->orWhereNull('issues.'.$column);
            })->whereNotExists(function (QueryBuilder $sub) use ($column, $values): void {
                $this->history($sub, $column, $values, false);
            });
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function history(QueryBuilder $sub, string $column, array $values, bool $oldOnly): void
    {
        $sub->selectRaw('1')
            ->from('journals')
            ->join('journal_details', 'journal_details.journal_id', '=', 'journals.id')
            ->where('journals.journalized_type', 'Issue')
            ->whereColumn('journals.journalized_id', 'issues.id')
            ->where('journal_details.property', 'attr')
            ->where('journal_details.prop_key', $column);

        if ($oldOnly) {
            $sub->whereIn('journal_details.old_value', $values);

            return;
        }

        $sub->where(function (QueryBuilder $sides) use ($values): void {
            $sides->whereIn('journal_details.old_value', $values)
                ->orWhereIn('journal_details.value', $values);
        });
    }
}
