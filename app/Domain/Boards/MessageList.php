<?php

namespace App\Domain\Boards;

/**
 * Sort and page a topic or reply list.
 *
 * The default topic order is sticky, then updated_on, then id, all descending.
 * A sort string is `column` or `column:asc` / `column:desc`, separated by commas.
 * Unknown columns are skipped. Page size defaults to 25 and stops at 100.
 */
final class MessageList
{
    /**
     * @var list<string>
     */
    private const COLUMNS = ['subject', 'created_on', 'replies_count', 'updated_on', 'sticky', 'id'];

    /**
     * @param  list<array<string, mixed>>  $topics
     * @return array{rows: list<array<string, mixed>>, page: int, per_page: int, total: int, pages: int}
     */
    public function topics(array $topics, ?string $sort, ?int $page, ?int $perPage): array
    {
        $rules = $this->rules($sort);
        usort($topics, function (array $left, array $right) use ($rules): int {
            foreach ($rules as $rule) {
                $compared = $this->value($left, $rule['column']) <=> $this->value($right, $rule['column']);
                if ($compared === 0) {
                    continue;
                }

                return $rule['direction'] === 'desc' ? -$compared : $compared;
            }

            return 0;
        });

        return $this->slice($topics, $page, $perPage);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, page: int, per_page: int, total: int, pages: int}
     */
    public function slice(array $rows, ?int $page, ?int $perPage): array
    {
        $size = $this->perPage($perPage);
        $total = count($rows);
        $pages = max(1, (int) ceil($total / $size));
        $current = $page === null || $page < 1 ? 1 : $page;
        if ($current > $pages) {
            $current = $pages;
        }
        $offset = ($current - 1) * $size;

        return [
            'rows' => array_slice($rows, $offset, $size),
            'page' => $current,
            'per_page' => $size,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    public function perPage(?int $perPage): int
    {
        if ($perPage === null || $perPage < 1) {
            return 25;
        }

        return min($perPage, 100);
    }

    /**
     * @return list<array{column: string, direction: string}>
     */
    private function rules(?string $sort): array
    {
        $rules = [];
        if (is_string($sort) && trim($sort) !== '') {
            foreach (explode(',', $sort) as $part) {
                $piece = trim($part);
                if ($piece === '') {
                    continue;
                }
                $split = explode(':', $piece, 2);
                $column = $split[0];
                $direction = isset($split[1]) ? strtolower($split[1]) : 'asc';
                if (! in_array($column, self::COLUMNS, true)) {
                    continue;
                }
                $rules[] = [
                    'column' => $column,
                    'direction' => $direction === 'desc' ? 'desc' : 'asc',
                ];
            }
        }
        if ($rules === []) {
            return [
                ['column' => 'sticky', 'direction' => 'desc'],
                ['column' => 'updated_on', 'direction' => 'desc'],
                ['column' => 'id', 'direction' => 'desc'],
            ];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function value(array $row, string $column): int|string
    {
        $value = $row[$column] ?? '';
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            return $value;
        }

        return '';
    }
}
