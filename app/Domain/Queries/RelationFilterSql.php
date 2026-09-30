<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Relation filters read `issue_relations`.
 *
 * Direction is stored once: the canonical type sits on `issue_from_id`
 * (`blocks`, `duplicates`, `precedes`, `copied_to`, `relates`) and the
 * reverse name is the other end (`blocked`, `duplicated`, `follows`,
 * `copied_from`). A filter matches either spelling so a row written with
 * only the canonical type still hits both sides.
 */
final class RelationFilterSql
{
    /**
     * Filter name => [type when this issue is issue_from_id, type when it is issue_to_id].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PAIRS = [
        'relates' => ['relates', 'relates'],
        'blocks' => ['blocks', 'blocked'],
        'blocked' => ['blocked', 'blocks'],
        'duplicates' => ['duplicates', 'duplicated'],
        'duplicated' => ['duplicated', 'duplicates'],
        'precedes' => ['precedes', 'follows'],
        'follows' => ['follows', 'precedes'],
        'copied_to' => ['copied_to', 'copied_from'],
        'copied_from' => ['copied_from', 'copied_to'],
    ];

    /**
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query, IssueField $field, QueryFilter $filter): void
    {
        $pair = self::PAIRS[$field->name] ?? null;
        if ($pair === null) {
            throw new QueryValidationException('Unknown filter field: '.$field->name.'.');
        }

        $operator = $filter->operator;
        $positive = match ($operator) {
            '!' => '=',
            '!*' => '*',
            '!p' => '=p',
            '!o' => '*o',
            default => $operator,
        };
        $negative = $positive !== $operator;
        $callback = function (QueryBuilder $sub) use ($pair, $positive, $filter): void {
            $this->base($sub, $pair[0], $pair[1]);
            $this->predicate($sub, $positive, $filter);
        };

        if ($negative) {
            $query->whereNotExists($callback);

            return;
        }

        $query->whereExists($callback);
    }

    private function base(QueryBuilder $sub, string $fromType, string $toType): void
    {
        $sub->selectRaw('1')
            ->from('issue_relations')
            ->join(
                'issues as related_issues',
                'related_issues.id',
                '=',
                DB::raw('CASE WHEN issue_relations.issue_from_id = issues.id THEN issue_relations.issue_to_id ELSE issue_relations.issue_from_id END'),
            )
            ->where(function (QueryBuilder $sides) use ($fromType, $toType): void {
                $sides->where(function (QueryBuilder $from) use ($fromType): void {
                    $from->whereColumn('issue_relations.issue_from_id', 'issues.id')
                        ->where('issue_relations.relation_type', $fromType);
                })->orWhere(function (QueryBuilder $to) use ($toType): void {
                    $to->whereColumn('issue_relations.issue_to_id', 'issues.id')
                        ->where('issue_relations.relation_type', $toType);
                });
            });
    }

    private function predicate(QueryBuilder $sub, string $operator, QueryFilter $filter): void
    {
        if ($operator === '*') {
            return;
        }

        if ($operator === '*o') {
            $sub->whereIn('related_issues.status_id', function (QueryBuilder $statuses): void {
                $statuses->select('id')->from('issue_statuses')->where('is_closed', false);
            });

            return;
        }

        if ($operator === '=') {
            $sub->whereIn('related_issues.id', FilterValues::ids($filter));

            return;
        }

        if ($operator === '=p') {
            $sub->whereIn('related_issues.project_id', FilterValues::ids($filter));

            return;
        }

        if ($operator === '=!p') {
            $sub->whereNotIn('related_issues.project_id', FilterValues::ids($filter));

            return;
        }

        throw new QueryValidationException('Operator '.$operator.' is not valid for '.$filter->field.'.');
    }
}
