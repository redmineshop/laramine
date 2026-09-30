<?php

namespace App\Domain\Queries;

/**
 * `queries.visibility` values.
 *
 * 0 is the owner only, 1 is the roles in `queries_roles`, 2 is logged-in users
 * who can see the query's project (or any logged-in user when the query is global).
 */
final class QueryVisibility
{
    public const PRIVATE = 0;

    public const ROLES = 1;

    public const PUBLIC = 2;

    public static function name(int $visibility): string
    {
        return match ($visibility) {
            self::PRIVATE => 'private',
            self::ROLES => 'roles',
            self::PUBLIC => 'public',
            default => throw new QueryValidationException('Query visibility must be 0, 1, or 2.'),
        };
    }
}
