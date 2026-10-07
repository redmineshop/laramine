<?php

namespace App\Domain\Queries;

/**
 * Projected IssueQuery result.
 *
 * `rows` is the filtered set in query order. `board` is empty for `list`
 * and status columns for `board`. Totals are not part of this view.
 */
final readonly class IssueQueryView
{
    /**
     * @param  list<string>  $columns
     * @param  list<IssueQueryRow>  $rows
     * @param  list<IssueQueryBoardColumn>  $board
     */
    public function __construct(
        public string $displayType,
        public array $columns,
        public array $rows,
        public array $board,
    ) {}
}
