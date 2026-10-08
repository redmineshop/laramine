<?php

namespace App\Domain\Queries;

/**
 * One status column of the Laramine issue-board extension.
 *
 * 7.0.1 IssueQuery does not have this display. ProjectQuery `board` is a
 * separate card layout and is not this column. Issue ids keep the query
 * sort. Statuses with no matching issue are omitted.
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
