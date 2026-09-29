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
 * Rows stay inside the actor's issue visibility. Column names are not projected;
 * callers read full issue rows and use `column_names` only as a display list.
 */
final class IssueQueryRunner
{
    public function __construct(
        private readonly VisibleIssueScope $scope,
        private readonly IssueQueryCompiler $compiler,
        private readonly IssueQuerySort $sort,
        private readonly SavedQueryService $saved,
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

        $project = null;
        if ($query->project_id !== null) {
            $loaded = $query->project;
            if (! $loaded instanceof Project) {
                return Issue::query()->whereRaw('1 = 0');
            }
            $project = $loaded;
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
     * @param  array<mixed>  $filters
     * @param  list<array{0: string, 1: string}>  $sort
     * @return Builder<Issue>
     */
    public function preview(?User $actor, ?Project $project, array $filters, array $sort = [], ?string $groupBy = null): Builder
    {
        $map = QueryPayload::filters($filters);
        $pairs = QueryPayload::sort($sort) ?? [];
        foreach ($pairs as [$column]) {
            $this->sort->column($column);
        }

        $group = $groupBy === null || $groupBy === '' ? null : $groupBy;
        if ($group !== null) {
            $this->sort->column($group);
        }

        $builder = $this->scope->apply(Issue::query(), $actor, $project);
        $this->compiler->apply(
            $builder,
            QueryFilter::listFromMap($map),
            $actor,
            $project,
            DateWindow::forUser($actor),
        );
        $this->sort->apply($builder, $group, $pairs);

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
}
