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
 * Status columns are built only when the Laramine board extension is on.
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
        private readonly IssueQueryGrouping $grouping,
        private readonly IssueQueryLayout $layout,
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
        $options = QueryPayload::options($query->options);
        $display = IssueQueryDisplay::resolve($options);
        $columns = $this->projection->names($actor, $project, QueryPayload::columnNames($query->column_names));
        [$inline, $block] = $this->layout->split($actor, $project, $columns);
        $groupBy = is_string($query->group_by) && $query->group_by !== '' ? $query->group_by : null;
        $groups = $groupBy !== null && $this->grouping->isGroupable($groupBy, $actor, $project)
            ? $this->grouping->summarize($actor, $project, $issues, $groupBy, $this->totals->columns($options, $actor, $project))
            : [];

        return new IssueQueryView(
            $display,
            $columns,
            $this->projection->rows($actor, $issues, $columns),
            $display === IssueQueryDisplay::BOARD ? $this->board->columns($issues) : [],
            $inline,
            $block,
            $groups,
        );
    }

    /**
     * Filters of a visible IssueQuery. Calendar and Gantt ignore group and sort.
     *
     * @return array<string, array{operator: string, values: list<string>}>
     */
    public function savedFilters(?User $actor, Query $query): array
    {
        $this->assertIssueQuery($actor, $query);

        return QueryPayload::filters($query->filters);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function savedOptions(?User $actor, Query $query): ?array
    {
        $this->assertIssueQuery($actor, $query);

        return QueryPayload::options($query->options);
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

        $builder = $this->filtered($actor, $project, $map);
        $this->sort->apply($builder, $group, $pairs, $actor, $project);

        return $builder;
    }

    /**
     * Filtered issues with no sort. Calendar and Gantt apply their own order.
     *
     * @param  array<mixed>  $filters
     * @return Builder<Issue>
     */
    public function matching(?User $actor, ?Project $project, array $filters): Builder
    {
        return $this->filtered($actor, $project, QueryPayload::filters($filters));
    }

    /**
     * @return list<string>
     */
    public function displayColumns(Query $query): array
    {
        $names = QueryPayload::columnNames($query->column_names);
        if ($names === null) {
            return $this->layout->defaultNames($this->boundProject($query));
        }

        return $names;
    }

    private function assertIssueQuery(?User $actor, Query $query): void
    {
        if ($query->type !== QueryType::ISSUE) {
            throw new QueryValidationException('Only IssueQuery can be executed.');
        }

        if (! $this->saved->canView($actor, $query)) {
            throw new DomainException('Saved query is not visible.');
        }
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
