<?php

namespace App\Domain\Queries;

/**
 * Projected IssueQuery result.
 *
 * `rows` is the filtered set in query order. `board` is empty unless the
 * Laramine status-column extension is on. `inlineColumns` and `blockColumns`
 * split that same column list. `groups` is empty when `group_by` is blank.
 */
final readonly class IssueQueryView
{
    /**
     * @param  list<string>  $columns
     * @param  list<IssueQueryRow>  $rows
     * @param  list<IssueQueryBoardColumn>  $board
     * @param  list<string>  $inlineColumns
     * @param  list<string>  $blockColumns
     * @param  list<array{key: string|null, value: string|null, count: int, ids: list<int>, totals: array<string, string>}>  $groups
     */
    public function __construct(
        public string $displayType,
        public array $columns,
        public array $rows,
        public array $board,
        public array $inlineColumns = [],
        public array $blockColumns = [],
        public array $groups = [],
    ) {}
}
