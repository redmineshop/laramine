<?php

namespace App\Domain\Queries;

use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\TimeEntries\TimeEntryQueryRunner;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Create, update, delete, and list saved queries.
 *
 * Visibility 0 is the owner (and an active admin). Visibility 1 requires one of
 * `queries_roles` on the query project, or on any project when the query is global.
 * Visibility 2 is any logged-in user who can see that project.
 */
final class SavedQueryService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly MembershipService $memberships,
        private readonly IssueQueryCompiler $compiler,
        private readonly IssueQuerySort $sort,
        private readonly IssueQueryTotals $totals,
        private readonly UserQueryCatalog $userQueries,
        private readonly TimeEntryQueryRunner $timeEntries,
        private readonly ProjectQueryFields $projectFields,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): Query
    {
        $type = $this->optionalString($attributes, 'type') ?? QueryType::ISSUE;
        QueryType::assert($type);
        $project = $this->projectFrom($attributes);
        if (! $this->canSave($actor, $project)) {
            throw new PermissionDeniedException('save_queries');
        }

        $visibility = $this->visibilityFrom($attributes, QueryVisibility::PRIVATE);
        $roleIds = $this->roleIds($attributes, $visibility);
        if ($visibility === QueryVisibility::ROLES && $roleIds === null) {
            throw new QueryValidationException('Role visibility needs at least one role.');
        }

        $filters = QueryPayload::filters($attributes['filters'] ?? []);
        $this->assertFilters($type, $filters, $actor, $project);
        $columns = array_key_exists('column_names', $attributes)
            ? QueryPayload::columnNames($attributes['column_names'])
            : null;
        $this->assertTimeEntryColumns($type, $columns, $actor, $project);
        $sort = array_key_exists('sort_criteria', $attributes)
            ? QueryPayload::sort($attributes['sort_criteria'])
            : null;
        $this->assertSort($type, $sort, $actor, $project);
        $groupBy = $this->groupBy($attributes['group_by'] ?? null, $type, $actor, $project);
        $options = array_key_exists('options', $attributes)
            ? QueryPayload::options($attributes['options'])
            : null;
        $this->assertOptions($type, $options, $actor, $project);

        return DB::transaction(function () use ($actor, $attributes, $type, $project, $visibility, $roleIds, $filters, $columns, $sort, $groupBy, $options): Query {
            $query = Query::query()->create([
                'name' => $this->name($attributes, true),
                'description' => $this->description($attributes),
                'type' => $type,
                'user_id' => $actor->id,
                'project_id' => $project?->id,
                'visibility' => $visibility,
                'filters' => $filters,
                'column_names' => $columns,
                'sort_criteria' => $sort,
                'group_by' => $groupBy,
                'options' => $options,
            ]);
            $this->syncRoles($query, $visibility, $roleIds ?? []);

            return $query->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Query $query, array $attributes): Query
    {
        if (! $this->canEdit($actor, $query)) {
            throw new DomainException('Saved query cannot be changed by this user.');
        }

        $type = array_key_exists('type', $attributes)
            ? ($this->optionalString($attributes, 'type') ?? (string) $query->type)
            : (string) $query->type;
        QueryType::assert($type);

        $project = array_key_exists('project_id', $attributes)
            ? $this->projectFrom($attributes)
            : $this->loadedProject($query);
        if ($query->project_id !== null && $project === null && ! array_key_exists('project_id', $attributes)) {
            throw new QueryValidationException('Query project is unknown.');
        }

        $visibility = array_key_exists('visibility', $attributes)
            ? $this->visibilityFrom($attributes, null)
            : (int) $query->visibility;
        $roleIds = $this->roleIds($attributes, $visibility);
        if ($visibility === QueryVisibility::ROLES && $roleIds === null && ! $this->hasRoles($query)) {
            throw new QueryValidationException('Role visibility needs at least one role.');
        }

        $filters = array_key_exists('filters', $attributes)
            ? QueryPayload::filters($attributes['filters'])
            : QueryPayload::filters($query->filters);
        $this->assertFilters($type, $filters, $actor, $project);

        $columns = array_key_exists('column_names', $attributes)
            ? QueryPayload::columnNames($attributes['column_names'])
            : QueryPayload::columnNames($query->column_names);
        $this->assertTimeEntryColumns($type, $columns, $actor, $project);
        $sort = array_key_exists('sort_criteria', $attributes)
            ? QueryPayload::sort($attributes['sort_criteria'])
            : QueryPayload::sort($query->sort_criteria);
        $this->assertSort($type, $sort, $actor, $project);
        $groupBy = array_key_exists('group_by', $attributes)
            ? $this->groupBy($attributes['group_by'], $type, $actor, $project)
            : $this->groupBy($query->group_by, $type, $actor, $project);
        $options = array_key_exists('options', $attributes)
            ? QueryPayload::options($attributes['options'])
            : QueryPayload::options($query->options);
        $this->assertOptions($type, $options, $actor, $project);

        return DB::transaction(function () use ($attributes, $query, $type, $project, $visibility, $roleIds, $filters, $columns, $sort, $groupBy, $options): Query {
            $query->fill([
                'type' => $type,
                'project_id' => $project?->id,
                'visibility' => $visibility,
                'filters' => $filters,
                'column_names' => $columns,
                'sort_criteria' => $sort,
                'group_by' => $groupBy,
                'options' => $options,
            ]);
            if (array_key_exists('name', $attributes)) {
                $query->name = $this->name($attributes, true);
            }
            if (array_key_exists('description', $attributes)) {
                $query->description = $this->description($attributes);
            }
            $query->save();
            $this->syncRoles($query, $visibility, $roleIds);

            return $query->refresh();
        });
    }

    public function delete(User $actor, Query $query): void
    {
        if (! $this->canEdit($actor, $query)) {
            throw new DomainException('Saved query cannot be changed by this user.');
        }

        DB::transaction(function () use ($query): void {
            DB::table('queries_roles')->where('query_id', $query->id)->delete();
            $query->delete();
        });
    }

    /**
     * @return Collection<int, Query>
     */
    public function visible(?User $actor, ?Project $project = null, ?string $type = null): Collection
    {
        $builder = Query::query()->orderBy('name')->orderBy('id');
        if ($type !== null) {
            QueryType::assert($type);
            $builder->where('queries.type', $type);
        }
        if ($project !== null) {
            $builder->where(function (Builder $inner) use ($project): void {
                /** @var Builder<Query> $inner */
                $inner->whereNull('queries.project_id')
                    ->orWhere('queries.project_id', $project->id);
            });
        }

        return $builder->get()->filter(fn (Query $row): bool => $this->canView($actor, $row))->values();
    }

    public function canView(?User $actor, Query $query): bool
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return true;
        }

        if (! $actor instanceof User || ! $this->permissions->isLoggedIn($actor)) {
            return false;
        }

        $visibility = (int) $query->visibility;
        if ($visibility === QueryVisibility::PRIVATE) {
            return (int) $query->user_id === (int) $actor->id;
        }

        $project = $this->loadedProject($query);
        if ($query->project_id !== null && $project === null) {
            return false;
        }
        if ($project !== null && ! $this->permissions->projectVisible($actor, $project)) {
            return false;
        }

        if ($visibility === QueryVisibility::PUBLIC) {
            return true;
        }

        if ($visibility !== QueryVisibility::ROLES) {
            return false;
        }

        $allowed = $this->storedRoleIds($query);
        if ($allowed === []) {
            return false;
        }

        $roles = $project !== null
            ? $this->memberships->rolesOnProject($actor, $project)
            : $this->memberships->rolesAcrossProjects($actor);
        foreach ($roles as $role) {
            if (in_array((int) $role->id, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    public function canEdit(User $actor, Query $query): bool
    {
        if ($actor->admin && $actor->isActive()) {
            return true;
        }

        return $this->permissions->isLoggedIn($actor)
            && (int) $query->user_id === (int) $actor->id;
    }

    public function canSave(User $actor, ?Project $project): bool
    {
        if (! $actor->isActive() || $actor->type === User::TYPE_ANONYMOUS) {
            return false;
        }

        if ($actor->admin) {
            return true;
        }

        if ($project !== null) {
            return $this->permissions->allowed($actor, 'save_queries', $project);
        }

        foreach ($this->memberships->rolesAcrossProjects($actor) as $role) {
            if ($role->grants('save_queries')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     */
    private function assertFilters(string $type, array $filters, User $actor, ?Project $project): void
    {
        if ($type === QueryType::USER) {
            $this->userQueries->assertFilters($filters);

            return;
        }
        if ($type === QueryType::TIME_ENTRY) {
            $this->timeEntries->assertStored($filters, null, null, null, $actor, $project);

            return;
        }
        if ($type === QueryType::PROJECT || $type === QueryType::PROJECT_ADMIN) {
            foreach (QueryFilter::listFromMap($filters) as $filter) {
                $this->projectFields->assertFilter($filter, $actor, $type === QueryType::PROJECT_ADMIN);
            }

            return;
        }
        if ($type !== QueryType::ISSUE) {
            if ($filters !== []) {
                throw new QueryValidationException('Only IssueQuery filters are implemented.');
            }

            return;
        }

        $this->compiler->apply(
            Issue::query(),
            QueryFilter::listFromMap($filters),
            $actor,
            $project,
            DateWindow::forUser($actor),
        );
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    private function assertOptions(string $type, ?array $options, User $actor, ?Project $project): void
    {
        if ($type === QueryType::TIME_ENTRY) {
            $this->timeEntries->assertStored([], null, null, $options, $actor, $project);

            return;
        }
        if ($type === QueryType::PROJECT || $type === QueryType::PROJECT_ADMIN) {
            $this->assertProjectTotals($options, $actor);

            return;
        }
        if ($type !== QueryType::ISSUE) {
            return;
        }

        IssueQueryDisplay::resolve($options);
        $this->totals->columns($options, $actor, $project);
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    private function assertProjectTotals(?array $options, User $actor): void
    {
        if ($options === null || ! array_key_exists('totalable_names', $options)) {
            return;
        }
        $raw = $options['totalable_names'];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new QueryValidationException('Query totals must be a list.');
        }
        $names = [];
        foreach ($raw as $item) {
            if (! is_string($item) || $item === '') {
                throw new QueryValidationException('Query totals must be a list of column names.');
            }
            $names[] = $item;
        }
        $this->projectFields->totals($actor, [], $names);
    }

    /**
     * @param  list<string>|null  $columns
     */
    private function assertTimeEntryColumns(string $type, ?array $columns, User $actor, ?Project $project): void
    {
        if ($type !== QueryType::TIME_ENTRY) {
            return;
        }

        $this->timeEntries->assertStored([], $columns, null, null, $actor, $project);
    }

    /**
     * @param  list<array{0: string, 1: string}>|null  $sort
     */
    private function assertSort(string $type, ?array $sort, User $actor, ?Project $project): void
    {
        if ($sort === null) {
            return;
        }

        if ($type === QueryType::USER) {
            foreach ($sort as [$column]) {
                $this->userQueries->assertSortColumn($column);
            }

            return;
        }
        if ($type === QueryType::TIME_ENTRY) {
            $this->timeEntries->assertStored([], null, $sort, null, $actor, $project);

            return;
        }
        if ($type === QueryType::PROJECT || $type === QueryType::PROJECT_ADMIN) {
            foreach ($sort as [$column, $direction]) {
                $this->projectFields->assertSort($column, $direction, $actor, ProjectQueryRunner::COLUMNS);
            }

            return;
        }

        foreach ($sort as [$column]) {
            $this->sort->assertAvailable($column, $type, $actor, $project);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function projectFrom(array $attributes): ?Project
    {
        if (! array_key_exists('project_id', $attributes)) {
            return null;
        }

        $raw = $attributes['project_id'];
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_int($raw) && ! (is_string($raw) && preg_match('/^[+]?\d+$/', $raw) === 1)) {
            throw new QueryValidationException('Query project is invalid.');
        }

        $project = Project::query()->find((int) $raw);
        if (! $project instanceof Project) {
            throw new QueryValidationException('Query project is unknown.');
        }

        return $project;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function visibilityFrom(array $attributes, ?int $fallback): int
    {
        if (! array_key_exists('visibility', $attributes) || $attributes['visibility'] === null) {
            if ($fallback === null) {
                throw new QueryValidationException('Query visibility must be 0, 1, or 2.');
            }

            return $fallback;
        }

        $raw = $attributes['visibility'];
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            $raw = (int) $raw;
        }
        if (! is_int($raw)) {
            throw new QueryValidationException('Query visibility must be 0, 1, or 2.');
        }

        QueryVisibility::name($raw);

        return $raw;
    }

    /**
     * Null means the caller omitted role_ids. An empty list is never returned for visibility 1.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int>|null
     */
    private function roleIds(array $attributes, int $visibility): ?array
    {
        if ($visibility !== QueryVisibility::ROLES) {
            return [];
        }

        if (! array_key_exists('role_ids', $attributes)) {
            return null;
        }

        $raw = $attributes['role_ids'];
        if (! is_array($raw) || $raw === []) {
            throw new QueryValidationException('Role visibility needs at least one role.');
        }

        $ids = [];
        foreach ($raw as $id) {
            if (! is_int($id) && ! (is_string($id) && preg_match('/^[+]?\d+$/', $id) === 1)) {
                throw new QueryValidationException('Query role id is invalid.');
            }
            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        $found = [];
        foreach (Role::query()->whereIn('id', $ids)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $found[] = (int) $id;
            }
        }
        sort($found);
        if ($ids !== $found) {
            throw new QueryValidationException('Query role id is unknown.');
        }

        return $ids;
    }

    /**
     * @param  list<int>|null  $roleIds
     */
    private function syncRoles(Query $query, int $visibility, ?array $roleIds): void
    {
        if ($visibility !== QueryVisibility::ROLES) {
            DB::table('queries_roles')->where('query_id', $query->id)->delete();

            return;
        }

        if ($roleIds === null) {
            return;
        }

        DB::table('queries_roles')->where('query_id', $query->id)->delete();
        foreach ($roleIds as $roleId) {
            DB::table('queries_roles')->insert([
                'query_id' => $query->id,
                'role_id' => $roleId,
            ]);
        }
    }

    private function hasRoles(Query $query): bool
    {
        return DB::table('queries_roles')->where('query_id', $query->id)->exists();
    }

    /**
     * @return list<int>
     */
    private function storedRoleIds(Query $query): array
    {
        $ids = [];
        foreach ($query->roles()->pluck('roles.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function loadedProject(Query $query): ?Project
    {
        if ($query->project_id === null) {
            return null;
        }

        $project = $query->project;

        return $project instanceof Project ? $project : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function name(array $attributes, bool $required): string
    {
        if (! array_key_exists('name', $attributes)) {
            if ($required) {
                throw new QueryValidationException('Query name is required.');
            }

            return '';
        }

        $name = $attributes['name'];
        if (! is_string($name)) {
            throw new QueryValidationException('Query name is required.');
        }

        $name = trim($name);
        if ($name === '' || strlen($name) > 255) {
            throw new QueryValidationException('Query name must be 1 to 255 characters.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function description(array $attributes): ?string
    {
        if (! array_key_exists('description', $attributes) || $attributes['description'] === null) {
            return null;
        }

        $description = $attributes['description'];
        if (! is_string($description) || strlen($description) > 255) {
            throw new QueryValidationException('Query description is invalid.');
        }

        return $description;
    }

    private function groupBy(mixed $value, string $type, User $actor, ?Project $project): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new QueryValidationException('Query group is invalid.');
        }

        if ($type === QueryType::USER) {
            $this->userQueries->assertSortColumn($value);

            return $value;
        }
        if ($type === QueryType::TIME_ENTRY) {
            throw new QueryValidationException('Time entry queries do not group rows.');
        }

        $this->sort->assertAvailable($value, $type, $actor, $project);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function optionalString(array $attributes, string $key): ?string
    {
        if (! array_key_exists($key, $attributes) || $attributes[$key] === null) {
            return null;
        }

        $value = $attributes[$key];
        if (! is_string($value) || $value === '') {
            throw new QueryValidationException('Query '.$key.' is invalid.');
        }

        return $value;
    }
}
