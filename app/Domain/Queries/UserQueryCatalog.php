<?php

namespace App\Domain\Queries;

/**
 * Filters and sort columns for a running `UserQuery`.
 *
 * `ProjectQuery` and `ProjectAdminQuery` stay unimplemented. `TimeEntryQuery` runs in `TimeEntryQueryRunner`.
 */
final class UserQueryCatalog
{
    /**
     * @var list<string>
     */
    public const OPERATORS = ['=', '!', '~', '!~', '*', '!*'];

    /**
     * @var list<string>
     */
    public const FIELDS = [
        'status',
        'login',
        'firstname',
        'lastname',
        'mail',
        'admin',
        'auth_source_id',
        'created_on',
        'last_login_on',
    ];

    /**
     * @var list<string>
     */
    public const SORTS = [
        'id',
        'login',
        'firstname',
        'lastname',
        'mail',
        'admin',
        'status',
        'created_on',
        'last_login_on',
    ];

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $filters
     */
    public function assertFilters(array $filters): void
    {
        foreach ($filters as $field => $filter) {
            if (! in_array($field, self::FIELDS, true)) {
                throw new QueryValidationException('Unknown user query field: '.$field.'.');
            }
            if (! in_array($filter['operator'], self::OPERATORS, true)) {
                throw new QueryValidationException('Unknown user query operator: '.$filter['operator'].'.');
            }
        }
    }

    public function assertSortColumn(string $name): void
    {
        if (! in_array($name, self::SORTS, true)) {
            throw new QueryValidationException('Sort column is not available: '.$name.'.');
        }
    }
}
