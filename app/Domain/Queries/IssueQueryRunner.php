<?php

namespace App\Domain\Queries;

use App\Domain\DomainException;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs a saved IssueQuery or an ephemeral filter set.
 *
 * Rows stay inside the actor's issue visibility. `execute` returns issue models.
 * `present` projects the available columns and applies `display_type`.
 * `totals` sums `options.totalable_names` over that same set.
 * Sort uses position, user name, related names, or a custom-field value.
 */
final class IssueQueryRunner
{
    public function __construct(
        private readonly VisibleIssueScope $scope,
        private readonly IssueQueryCompiler $compiler,
        private readonly IssueQuerySort $sort,
        private readonly IssueQueryTotals $totals,
        private readonly SavedQueryService $saved,
        private readonly IssueQueryProjection $projection,
        private readonly IssueQueryBoard $board,
    ) {}

    /**
     * @return Builder<Issue>
     */
    public function execute(?User $actor, Query $query): Builder
    {
        if ($query->type !== QueryType::ISSUE) {
            throw new QueryValidationException('Only IssueQuery can be executed.');
        }

        if (! $this->saved->canView($actor, $query)) {
            throw new DomainException('Saved query is not visible.');
        }

        IssueQueryDisplay::resolve(QueryPayload::options($query->options));

        $project = $this->boundProject($query);
        if ($query->project_id !== null && $project === null) {
            return Issue::query()->whereRaw('1 = 0');
        }

        $sort = QueryPayload::sort($query->sort_criteria);

        return $this->preview(
            $actor,
            $project,
            QueryPayload::filters($query->filters),
            $sort ?? [],
            is_string($query->group_by) ? $query->group_by : null,
        );
    }

    /**
     * Sums the query's `totalable_names` over the same issues `execute` would return.
     *
     * An omitted or empty list returns an empty map. Sort does not change the sums.
     *
     * @return array<string, string>
     */
    public function totals(?User $actor, Query $query): array
    {
        if ($query->type !== QueryType::ISSUE) {
            throw new QueryValidationException('Only IssueQuery can be executed.');
        }

        if (! $this->saved->canView($actor, $query)) {
            throw new DomainException('Saved query is not visible.');
        }

        $options = QueryPayload::options($query->options);
        IssueQueryDisplay::resolve($options);

        $project = $this->boundProject($query);
        $columns = $this->totals->columns($options, $actor, $project);
        if ($query->project_id !== null && $project === null) {
            return $this->totals->sum(Issue::query()->whereRaw('1 = 0'), $columns, $actor);
        }

        return $this->totals->sum(
            $this->filtered($actor, $project, QueryPayload::filters($query->filters)),
            $columns,
            $actor,
        );
    }

    /**
     * Projected rows for a saved IssueQuery. `execute` is the same issue set.
     */
    public function present(?User $actor, Query $query): IssueQueryView
    {
        $issues = $this->execute($actor, $query)->get();
        $project = $this->boundProject($query);
        $display = IssueQueryDisplay::resolve(QueryPayload::options($query->options));
        $columns = $this->projection->names($actor, $project, QueryPayload::columnNames($query->column_names));

        return new IssueQueryView(
            $display,
            $columns,
            $this->projection->rows($actor, $issues, $columns),
            $display === IssueQueryDisplay::BOARD ? $this->board->columns($issues) : [],
        );
    }

    /**
     * @param  array<mixed>  $filters
     * @param  list<array{0: string, 1: string}>  $sort
     * @return Builder<Issue>
     */
    public function preview(?User $actor, ?Project $project, array $filters, array $sort = [], ?string $groupBy = null): Builder
    {
        $map = QueryPayload::filters($filters);
        $pairs = QueryPayload::sort($sort) ?? [];
        foreach ($pairs as [$column]) {
            $this->sort->assertAvailable($column, QueryType::ISSUE, $actor, $project);
        }

        $group = $groupBy === null || $groupBy === '' ? null : $groupBy;
        if ($group !== null) {
            $this->sort->assertAvailable($group, QueryType::ISSUE, $actor, $project);
        }

        $builder = $this->filtered($actor, $project, $map);
        $this->sort->apply($builder, $group, $pairs, $actor, $project);

        return $builder;
    }

    /**
     * @return list<string>
     */
    public function displayColumns(Query $query): array
    {
        $names = QueryPayload::columnNames($query->column_names);
        if ($names === null) {
            return IssueQueryColumns::DEFAULT;
        }

        return $names;
    }

    /**
     * @param  array<mixed>  $filters
     * @return Builder<Issue>
     */
    private function filtered(?User $actor, ?Project $project, array $filters): Builder
    {
        $list = QueryFilter::listFromMap(QueryPayload::filters($filters));
        $builder = $this->scope->apply(Issue::query(), $actor, $project, $list);
        $this->compiler->apply(
            $builder,
            $list,
            $actor,
            $project,
            DateWindow::forUser($actor),
        );

        return $builder;
    }

    private function boundProject(Query $query): ?Project
    {
        if ($query->project_id === null) {
            return null;
        }

        $loaded = $query->project;

        return $loaded instanceof Project ? $loaded : null;
    }
}
