<?php

namespace App\Domain\Queries;

use App\Domain\Acl\UserVisibility;
use App\Domain\PermissionDeniedException;
use App\Models\Query;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Runs a `UserQuery` inside `UserVisibility`.
 *
 * The default order is lastname, then firstname, then id.
 */
final class UserQueryRunner
{
    public function __construct(
        private readonly UserVisibility $visibility,
        private readonly UserQueryCatalog $catalog,
        private readonly SavedQueryService $saved,
    ) {}

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     * @param  list<array{0: string, 1: string}>|null  $sort
     * @return list<int>
     */
    public function preview(?User $actor, array $filters, ?array $sort = null): array
    {
        $this->catalog->assertFilters($filters);
        if ($sort !== null) {
            foreach ($sort as [$column]) {
                $this->catalog->assertSortColumn($column);
            }
        }

        $query = User::query()->select('users.*');
        $this->visibility->apply($query, $actor);
        $this->applyFilters($query, $filters);
        $this->applySort($query, $sort);

        $ids = [];
        foreach ($query->pluck('users.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    public function execute(User $actor, Query $query): array
    {
        if ((string) $query->type !== QueryType::USER) {
            throw new QueryValidationException('Only UserQuery can be executed.');
        }
        if (! $this->saved->canView($actor, $query)) {
            throw new PermissionDeniedException('save_queries');
        }

        return $this->preview(
            $actor,
            QueryPayload::filters($query->filters),
            QueryPayload::sort($query->sort_criteria),
        );
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $field => $filter) {
            $operator = $filter['operator'];
            $values = $filter['values'];
            if ($operator === '*' || $operator === '!*') {
                $this->presence($query, $field, $operator === '*');

                continue;
            }

            $value = $values[0] ?? '';
            if ($field === 'mail') {
                $this->mail($query, $operator, $value);

                continue;
            }

            $column = match ($field) {
                'status', 'login', 'firstname', 'lastname', 'admin', 'auth_source_id', 'created_on', 'last_login_on' => 'users.'.$field,
                default => throw new QueryValidationException('Unknown user query field: '.$field.'.'),
            };
            $this->compare($query, $column, $operator, $value);
        }
    }

    /**
     * @param  Builder<User>  $query
     */
    private function presence(Builder $query, string $field, bool $present): void
    {
        if ($field === 'mail') {
            $exists = function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('email_addresses')
                    ->whereColumn('email_addresses.user_id', 'users.id');
            };
            if ($present) {
                $query->whereExists($exists);
            } else {
                $query->whereNotExists($exists);
            }

            return;
        }

        $column = match ($field) {
            'auth_source_id', 'last_login_on', 'created_on' => 'users.'.$field,
            default => throw new QueryValidationException('Presence is not available for '.$field.'.'),
        };
        if ($present) {
            $query->whereNotNull($column);
        } else {
            $query->whereNull($column);
        }
    }

    /**
     * @param  Builder<User>  $query
     */
    private function mail(Builder $query, string $operator, string $value): void
    {
        $positive = match ($operator) {
            '!' => '=',
            '!~' => '~',
            default => $operator,
        };
        $exists = function (QueryBuilder $sub) use ($positive, $value): void {
            $sub->selectRaw('1')
                ->from('email_addresses')
                ->whereColumn('email_addresses.user_id', 'users.id');
            $this->compareRaw($sub, 'email_addresses.address', $positive, $value);
        };
        if ($operator === '!' || $operator === '!~') {
            $query->whereNotExists($exists);

            return;
        }

        $query->whereExists($exists);
    }

    /**
     * @param  Builder<User>  $query
     */
    private function compare(Builder $query, string $column, string $operator, string $value): void
    {
        match ($operator) {
            '=' => $query->whereRaw('LOWER('.$column.') = LOWER(?)', [$value]),
            '!' => $query->whereRaw('LOWER('.$column.') <> LOWER(?)', [$value]),
            '~' => $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.$this->like($value).'%']),
            '!~' => $query->whereRaw('LOWER('.$column.') NOT LIKE ?', ['%'.$this->like($value).'%']),
            default => throw new QueryValidationException('Unknown user query operator: '.$operator.'.'),
        };
    }

    private function compareRaw(QueryBuilder $query, string $column, string $operator, string $value): void
    {
        match ($operator) {
            '=' => $query->whereRaw('LOWER('.$column.') = LOWER(?)', [$value]),
            '~' => $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.$this->like($value).'%']),
            default => throw new QueryValidationException('Unknown user query operator: '.$operator.'.'),
        };
    }

    /**
     * @param  Builder<User>  $query
     * @param  list<array{0: string, 1: string}>|null  $sort
     */
    private function applySort(Builder $query, ?array $sort): void
    {
        $pairs = $sort === null || $sort === []
            ? [['lastname', 'asc'], ['firstname', 'asc'], ['id', 'asc']]
            : $sort;
        foreach ($pairs as [$column, $direction]) {
            $sql = $column === 'mail'
                ? '(SELECT MIN(email_addresses.address) FROM email_addresses WHERE email_addresses.user_id = users.id AND email_addresses.is_default = 1)'
                : 'users.'.$column;
            $query->orderByRaw($sql.' '.($direction === 'desc' ? 'desc' : 'asc'));
        }
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
