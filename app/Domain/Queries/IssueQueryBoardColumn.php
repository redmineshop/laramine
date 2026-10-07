<?php

namespace App\Domain\Queries;

/**
 * One status column of a board display.
 *
 * Issue ids keep the query sort. Statuses with no matching issue are omitted.
 */
final readonly class IssueQueryBoardColumn
{
    /**
     * @param  list<int>  $issueIds
     */
    public function __construct(
        public int $statusId,
        public string $name,
        public array $issueIds,
    ) {}
}
