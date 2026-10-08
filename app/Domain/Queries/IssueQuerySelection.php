<?php

namespace App\Domain\Queries;

use App\Domain\Auth\PreferenceCodec;
use App\Domain\DomainException;
use App\Domain\Settings\SettingValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query;
use App\Models\User;
use App\Models\UserPreference;

/**
 * Chooses the IssueQuery for a list, calendar, or gantt.
 *
 * `query_id` loads a saved query whose project is global or the current project,
 * then rebinds it to the current project without saving. Otherwise a session for
 * the same project is restored. A new filter set is stored in the session.
 * When nothing is chosen, the user preference `default_issue_query` wins, then a
 * public project default, then the public `default_issue_query` setting.
 * A freshly built query sorts by id descending and filters open issues.
 */
final class IssueQuerySelection
{
    public function __construct(
        private readonly SavedQueryService $saved,
        private readonly IssueQueryCompiler $compiler,
        private readonly IssueQuerySort $sort,
        private readonly IssueQueryLayout $layout,
        private readonly SettingValue $settings,
        private readonly PreferenceCodec $preferences,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>|null  $session
     * @return array{
     *     source: string,
     *     query_id: int|null,
     *     project_id: int|null,
     *     filters: array<string, array{operator: string, values: list<string>}>,
     *     column_names: list<string>|null,
     *     sort: list<array{0: string, 1: string}>,
     *     group_by: string|null,
     *     totalable_names: list<string>,
     *     session: array<string, mixed>|null
     * }
     */
    public function select(?User $actor, ?Project $project, array $params, ?array $session, bool $useSession = true, bool $applyDefault = true): array
    {
        $queryId = $this->identifier($params['query_id'] ?? null);
        $setFilter = $this->flag($params['set_filter'] ?? null);
        $withoutDefault = $this->flag($params['without_default'] ?? null);
        $sortGiven = array_key_exists('sort', $params);

        if ($applyDefault && $queryId === null && ! $setFilter && ! $withoutDefault && ! $sortGiven && $session === null) {
            $queryId = $this->defaultId($actor, $project);
        }

        if ($queryId !== null) {
            $loaded = $this->find($actor, $project, $queryId);
            $next = $useSession ? ['id' => (int) $loaded->id, 'project_id' => $project?->id] : null;

            return $this->payload($actor, 'query_id', $loaded, $project, $next, $params);
        }

        if (! $useSession || ! is_array($session) || $setFilter || ! $this->sameProject($session, $project)) {
            $built = $this->build($actor, $project, $params);
            $next = $useSession ? $this->sessionFrom($built, $project) : null;

            return $this->payload($actor, 'params', $built, $project, $next, $params);
        }

        $restored = $this->restore($actor, $project, $session);

        return $this->payload($actor, 'session', $restored, $project, $session, $params);
    }

    public function defaultId(?User $actor, ?Project $project): ?int
    {
        $preferred = $this->preferenceId($actor);
        if ($preferred !== null && $this->usable($actor, $preferred, false)) {
            return $preferred;
        }

        $projectDefault = $project?->default_issue_query_id;
        if (is_numeric($projectDefault) && $this->usable($actor, (int) $projectDefault, true)) {
            return (int) $projectDefault;
        }

        $setting = $this->settings->defaultIssueQueryId();
        if ($setting !== null && $this->usable($actor, $setting, true)) {
            return $setting;
        }

        return null;
    }

    private function find(?User $actor, ?Project $project, int $id): Query
    {
        $scope = Query::query()->whereKey($id)->where('type', QueryType::ISSUE);
        if ($project === null) {
            $scope->whereNull('project_id');
        } else {
            $scope->where(function ($inner) use ($project): void {
                $inner->whereNull('project_id')->orWhere('project_id', $project->id);
            });
        }

        $query = $scope->first();
        if (! $query instanceof Query || ! $this->saved->canView($actor, $query)) {
            throw new DomainException('Saved query is not visible.');
        }

        return $this->rebind($query, $project);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function build(?User $actor, ?Project $project, array $params): Query
    {
        $filters = array_key_exists('filters', $params)
            ? QueryPayload::filters($params['filters'])
            : ['status_id' => ['operator' => 'o', 'values' => []]];
        $this->compiler->apply(
            Issue::query(),
            QueryFilter::listFromMap($filters),
            $actor,
            $project,
            DateWindow::forUser($actor),
        );

        $group = null;
        if (array_key_exists('group_by', $params) && $params['group_by'] !== null && $params['group_by'] !== '') {
            if (! is_string($params['group_by'])) {
                throw new QueryValidationException('Query group is invalid.');
            }
            $this->sort->assertAvailable($params['group_by'], QueryType::ISSUE, $actor, $project);
            $group = $params['group_by'];
        }

        $columns = array_key_exists('column_names', $params) ? QueryPayload::columnNames($params['column_names']) : null;
        $sort = array_key_exists('sort', $params) ? (QueryPayload::sort($params['sort']) ?? $this->layout->defaultSort()) : $this->layout->defaultSort();
        foreach ($sort as [$name]) {
            $this->sort->assertAvailable($name, QueryType::ISSUE, $actor, $project);
        }

        $totals = array_key_exists('totalable_names', $params)
            ? $this->names($params['totalable_names'])
            : $this->layout->defaultTotals();

        $query = new Query;
        $query->type = QueryType::ISSUE;
        $query->name = '_';
        $query->user_id = $actor instanceof User ? (int) $actor->id : 0;
        $query->project_id = $project?->id;
        $query->visibility = QueryVisibility::PRIVATE;
        $query->filters = $filters;
        $query->column_names = $columns;
        $query->sort_criteria = $sort;
        $query->group_by = $group;
        $query->options = [
            'display_type' => 'list',
            'totalable_names' => $totals,
        ];

        return $query;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function restore(?User $actor, ?Project $project, array $session): Query
    {
        $id = $this->identifier($session['id'] ?? null);
        if ($id !== null) {
            $found = Query::query()->whereKey($id)->where('type', QueryType::ISSUE)->first();
            if ($found instanceof Query && $this->saved->canView($actor, $found)) {
                return $this->rebind($found, $project);
            }
        }

        $params = [
            'filters' => $session['filters'] ?? ['status_id' => ['operator' => 'o', 'values' => []]],
            'group_by' => $session['group_by'] ?? null,
            'column_names' => $session['column_names'] ?? null,
            'sort' => $session['sort'] ?? $this->layout->defaultSort(),
            'totalable_names' => $session['totalable_names'] ?? [],
            'set_filter' => true,
        ];

        return $this->build($actor, $project, $params);
    }

    /**
     * @param  array<string, mixed>|null  $session
     * @param  array<string, mixed>  $params
     * @return array{
     *     source: string,
     *     query_id: int|null,
     *     project_id: int|null,
     *     filters: array<string, array{operator: string, values: list<string>}>,
     *     column_names: list<string>|null,
     *     sort: list<array{0: string, 1: string}>,
     *     group_by: string|null,
     *     totalable_names: list<string>,
     *     session: array<string, mixed>|null
     * }
     */
    private function payload(?User $actor, string $source, Query $query, ?Project $project, ?array $session, array $params): array
    {
        if (array_key_exists('sort', $params)) {
            $sort = QueryPayload::sort($params['sort']) ?? [];
            foreach ($sort as [$name]) {
                $this->sort->assertAvailable($name, QueryType::ISSUE, $actor, $project);
            }
            $query->sort_criteria = $sort;
            if (is_array($session)) {
                $session['sort'] = $sort;
            }
        }

        $options = QueryPayload::options($query->options);
        $totals = [];
        if (is_array($options) && isset($options['totalable_names']) && is_array($options['totalable_names'])) {
            foreach ($options['totalable_names'] as $name) {
                if (is_string($name)) {
                    $totals[] = $name;
                }
            }
        }

        return [
            'source' => $source,
            'query_id' => $this->storedId($query),
            'project_id' => $project?->id,
            'filters' => QueryPayload::filters($query->filters),
            'column_names' => QueryPayload::columnNames($query->column_names),
            'sort' => QueryPayload::sort($query->sort_criteria) ?? [],
            'group_by' => is_string($query->group_by) && $query->group_by !== '' ? $query->group_by : null,
            'totalable_names' => $totals,
            'session' => $session,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionFrom(Query $query, ?Project $project): array
    {
        $options = QueryPayload::options($query->options);

        return [
            'project_id' => $project?->id,
            'filters' => QueryPayload::filters($query->filters),
            'group_by' => $query->group_by,
            'column_names' => QueryPayload::columnNames($query->column_names),
            'totalable_names' => $this->stringList(is_array($options) ? ($options['totalable_names'] ?? []) : []),
            'sort' => QueryPayload::sort($query->sort_criteria) ?? [],
        ];
    }

    private function rebind(Query $query, ?Project $project): Query
    {
        $copy = $query->replicate();
        $copy->id = $query->id;
        $copy->exists = false;
        $copy->project_id = $project?->id;

        return $copy;
    }

    private function usable(?User $actor, int $id, bool $publicOnly): bool
    {
        $query = Query::query()->find($id);
        if (! $query instanceof Query || $query->type !== QueryType::ISSUE) {
            return false;
        }
        if ($publicOnly && (int) $query->visibility !== QueryVisibility::PUBLIC) {
            return false;
        }

        return $this->saved->canView($actor, $query);
    }

    private function preferenceId(?User $actor): ?int
    {
        if (! $actor instanceof User) {
            return null;
        }

        $stored = UserPreference::query()->where('user_id', $actor->id)->value('others');
        $decoded = $this->preferences->decode(is_string($stored) ? $stored : null);
        $value = $decoded['default_issue_query'] ?? null;
        if (! is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sameProject(array $session, ?Project $project): bool
    {
        if (! array_key_exists('project_id', $session)) {
            return false;
        }

        $stored = $session['project_id'];
        $current = $project?->id;
        if ($stored === null) {
            return $current === null;
        }

        return is_numeric($stored) && $current !== null && (int) $stored === $current;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $names[] = $item;
            }
        }

        return $names;
    }

    private function storedId(Query $query): ?int
    {
        $attributes = $query->getAttributes();
        if (! array_key_exists('id', $attributes)) {
            return null;
        }

        $id = $attributes['id'];
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }
        if (is_string($id) && preg_match('/^[1-9]\d*$/', $id) === 1) {
            return (int) $id;
        }

        return null;
    }

    private function identifier(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        throw new QueryValidationException('Query id is invalid.');
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @return list<string>
     */
    private function names(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new QueryValidationException('Query totals must be a list.');
        }

        $names = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new QueryValidationException('Query totals must be a list of column names.');
            }
            $names[] = $item;
        }

        return $names;
    }
}
