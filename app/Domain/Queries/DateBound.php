<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Inclusive date or datetime window. A null side is unbounded.
 * An empty window matches no rows.
 */
final class DateBound
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly bool $empty = false,
    ) {}

    /**
     * @param  Builder<Issue>|QueryBuilder  $query
     */
    public function apply(Builder|QueryBuilder $query, string $column): void
    {
        if ($this->empty || ($this->from === null && $this->to === null)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($this->from !== null && $this->to !== null) {
            $query->whereBetween($column, [$this->from, $this->to]);

            return;
        }

        if ($this->from !== null) {
            $query->where($column, '>=', $this->from);

            return;
        }

        $query->where($column, '<=', $this->to);
    }
}
