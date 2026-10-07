<?php

namespace App\Domain\Queries;

/**
 * STI names stored in `queries.type`.
 *
 * IssueQuery and UserQuery are executed. The other names can be stored as stubs.
 */
final class QueryType
{
    public const ISSUE = 'IssueQuery';

    public const PROJECT = 'ProjectQuery';

    public const TIME_ENTRY = 'TimeEntryQuery';

    public const USER = 'UserQuery';

    public const PROJECT_ADMIN = 'ProjectAdminQuery';

    /**
     * @var list<string>
     */
    public const ALL = [
        self::ISSUE,
        self::PROJECT,
        self::TIME_ENTRY,
        self::USER,
        self::PROJECT_ADMIN,
    ];

    public static function assert(string $type): void
    {
        if (! in_array($type, self::ALL, true)) {
            throw new QueryValidationException('Unknown query type: '.$type.'.');
        }
    }
}
