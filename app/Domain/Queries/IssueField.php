<?php

namespace App\Domain\Queries;

/**
 * One core issue filter and the column it constrains.
 */
final class IssueField
{
    public function __construct(
        public readonly string $name,
        public readonly string $filterType,
        public readonly IssueFilterKind $kind,
        public readonly string $column,
    ) {}

    public function sql(): string
    {
        return 'issues.'.$this->column;
    }
}
