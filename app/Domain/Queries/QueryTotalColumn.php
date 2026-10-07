<?php

namespace App\Domain\Queries;

/**
 * One name from `options.totalable_names` after it has been accepted.
 */
final class QueryTotalColumn
{
    public function __construct(
        public readonly string $name,
        public readonly QueryTotalSource $source,
        public readonly ?int $customFieldId,
    ) {}
}
