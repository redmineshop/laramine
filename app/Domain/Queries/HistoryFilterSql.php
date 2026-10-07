<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * History operators read `journals` / `journal_details`.
 *
 * Core columns use `property = attr` and `prop_key` the issue column.
 * Custom fields use `property = cf` and `prop_key` the custom field id.
 * Compared strings are the stored detail text. A multi-value custom field
 * stores one comma-joined string, and that whole string is what matches.
 * `ev` matches the current value or either journal side. `cf` matches
 * `old_value` only. `!ev` matches neither. Private journals are hidden
 * with the same rule as `updated_by`.
 */
final class HistoryFilterSql
{
    public function __construct(private readonly JournalVisibility $journals) {}

    /**
     * @param  Builder<Issue>  $query
     * @param  list<string>  $values
     */
    public function apply(Builder $query, string $column, string $operator, array $values, ?User $actor, ?Project $project): void
    {
        if ($operator === 'cf') {
            $query->whereExists(function (QueryBuilder $sub) use ($column, $values, $actor, $project): void {
                $this->history($sub, 'attr', $column, $values, true, $actor, $project);
            });

            return;
        }

        if ($operator === 'ev') {
            $query->where(function (Builder $inner) use ($column, $values, $actor, $project): void {
                /** @var Builder<Issue> $inner */
                $inner->whereIn('issues.'.$column, $values)
                    ->orWhereExists(function (QueryBuilder $sub) use ($column, $values, $actor, $project): void {
                        $this->history($sub, 'attr', $column, $values, false, $actor, $project);
                    });
            });

            return;
        }

        if ($operator !== '!ev') {
            throw new QueryValidationException('Operator '.$operator.' is not a history operator.');
        }

        $query->where(function (Builder $inner) use ($column, $values, $actor, $project): void {
            /** @var Builder<Issue> $inner */
            $inner->where(function (Builder $current) use ($column, $values): void {
                /** @var Builder<Issue> $current */
                $current->whereNotIn('issues.'.$column, $values)->orWhereNull('issues.'.$column);
            })->whereNotExists(function (QueryBuilder $sub) use ($column, $values, $actor, $project): void {
                $this->history($sub, 'attr', $column, $values, false, $actor, $project);
            });
        });
    }

    /**
     * `property = cf` details for one issue custom field. The current value is
     * any stored `custom_values` row, not the comma-joined journal string.
     *
     * @param  Builder<Issue>  $query
     * @param  list<string>  $values
     */
    public function applyCustomField(Builder $query, int $fieldId, string $operator, array $values, ?User $actor, ?Project $project): void
    {
        $propKey = (string) $fieldId;

        if ($operator === 'cf') {
            $query->whereExists(function (QueryBuilder $sub) use ($propKey, $values, $actor, $project): void {
                $this->history($sub, 'cf', $propKey, $values, true, $actor, $project);
            });

            return;
        }

        if ($operator === 'ev') {
            $query->where(function (Builder $inner) use ($fieldId, $propKey, $values, $actor, $project): void {
                /** @var Builder<Issue> $inner */
                $inner->whereExists(function (QueryBuilder $sub) use ($fieldId, $values): void {
                    $this->currentCustomValue($sub, $fieldId, $values);
                })->orWhereExists(function (QueryBuilder $sub) use ($propKey, $values, $actor, $project): void {
                    $this->history($sub, 'cf', $propKey, $values, false, $actor, $project);
                });
            });

            return;
        }

        if ($operator !== '!ev') {
            throw new QueryValidationException('Operator '.$operator.' is not a history operator.');
        }

        $query->where(function (Builder $inner) use ($fieldId, $propKey, $values, $actor, $project): void {
            /** @var Builder<Issue> $inner */
            $inner->whereNotExists(function (QueryBuilder $sub) use ($fieldId, $values): void {
                $this->currentCustomValue($sub, $fieldId, $values);
            })->whereNotExists(function (QueryBuilder $sub) use ($propKey, $values, $actor, $project): void {
                $this->history($sub, 'cf', $propKey, $values, false, $actor, $project);
            });
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function history(QueryBuilder $sub, string $property, string $propKey, array $values, bool $oldOnly, ?User $actor, ?Project $project): void
    {
        $sub->selectRaw('1')
            ->from('journals')
            ->join('journal_details', 'journal_details.journal_id', '=', 'journals.id')
            ->where('journals.journalized_type', 'Issue')
            ->whereColumn('journals.journalized_id', 'issues.id')
            ->where('journal_details.property', $property)
            ->where('journal_details.prop_key', $propKey);
        $this->journals->constrain($sub, $actor, $project);

        if ($oldOnly) {
            $sub->whereIn('journal_details.old_value', $values);

            return;
        }

        $sub->where(function (QueryBuilder $sides) use ($values): void {
            $sides->whereIn('journal_details.old_value', $values)
                ->orWhereIn('journal_details.value', $values);
        });
    }

    /**
     * @param  list<string>  $values
     */
    private function currentCustomValue(QueryBuilder $sub, int $fieldId, array $values): void
    {
        $sub->selectRaw('1')
            ->from('custom_values')
            ->where('custom_values.customized_type', 'Issue')
            ->whereColumn('custom_values.customized_id', 'issues.id')
            ->where('custom_values.custom_field_id', $fieldId)
            ->whereIn('custom_values.value', $values);
    }
}
