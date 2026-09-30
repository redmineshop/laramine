<?php

namespace App\Domain\Queries;

/**
 * One filter clause. Values are strings, matching the JSON stored in `queries.filters`.
 */
final class QueryFilter
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public readonly string $field,
        public readonly string $operator,
        public readonly array $values,
    ) {}

    /**
     * @param  array<string, array{operator: string, values: list<string>}>  $map
     * @return list<self>
     */
    public static function listFromMap(array $map): array
    {
        $filters = [];
        foreach ($map as $field => $clause) {
            $filters[] = new self($field, $clause['operator'], $clause['values']);
        }

        return $filters;
    }
}
