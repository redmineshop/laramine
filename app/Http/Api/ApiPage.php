<?php

namespace App\Http\Api;

use Illuminate\Http\Request;

/**
 * Offset, limit, and the nometa flag from a REST query string.
 *
 * Limit defaults to 25 and is capped at 100. A missing or non-positive
 * limit uses the default. nometa drops total_count, offset, and limit.
 */
final class ApiPage
{
    public function __construct(
        public readonly int $offset,
        public readonly int $limit,
        public readonly bool $nometa,
    ) {}

    public static function from(Request $request): self
    {
        $offsetRaw = $request->query('offset', 0);
        $offset = is_numeric($offsetRaw) ? (int) $offsetRaw : 0;
        if ($offset < 0) {
            $offset = 0;
        }

        $limitRaw = $request->query('limit', 25);
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 25;
        if ($limit < 1) {
            $limit = 25;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $nometa = $request->query('nometa');

        return new self($offset, $limit, $nometa === '1' || $nometa === 'true');
    }

    /**
     * @template T
     *
     * @param  array<array-key, T>  $rows
     * @return array{rows: list<T>, total: int}
     */
    public function slice(array $rows): array
    {
        $selected = [];
        $index = 0;
        foreach ($rows as $row) {
            if ($index >= $this->offset && count($selected) < $this->limit) {
                $selected[] = $row;
            }
            $index++;
        }

        return [
            'rows' => $selected,
            'total' => $index,
        ];
    }
}
