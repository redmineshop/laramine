<?php

namespace App\Domain\Queries;

/**
 * One issue in a projected query result.
 *
 * `values` holds only the available columns selected for this actor.
 * The issue id stays on the row even when `id` is not one of those columns.
 */
final readonly class IssueQueryRow
{
    /**
     * @param  array<string, string|null>  $values
     */
    public function __construct(
        public int $issueId,
        public array $values,
    ) {}
}
