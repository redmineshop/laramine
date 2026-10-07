<?php

namespace App\Domain\Files;

/**
 * Sort of files inside one project or version container.
 *
 * `filename` is case-insensitive ascending, then id ascending. `created_on`,
 * `size`, and `downloads` are descending, then id descending. Any other name
 * sorts as `filename`.
 *
 * @phpstan-type FileRow array{id: int, filename: string, created_on: string, filesize: int, downloads: int}
 */
final class AttachmentSort
{
    /**
     * @param  list<FileRow>  $rows
     * @return list<int>
     */
    public static function ids(array $rows, string $sortBy): array
    {
        $sort = $sortBy;
        usort($rows, function (array $left, array $right) use ($sort): int {
            $primary = match ($sort) {
                'created_on' => $right['created_on'] <=> $left['created_on'],
                'size' => $right['filesize'] <=> $left['filesize'],
                'downloads' => $right['downloads'] <=> $left['downloads'],
                default => mb_strtolower($left['filename']) <=> mb_strtolower($right['filename']),
            };
            if ($primary !== 0) {
                return $primary;
            }
            if ($sort === 'created_on' || $sort === 'size' || $sort === 'downloads') {
                return $right['id'] <=> $left['id'];
            }

            return $left['id'] <=> $right['id'];
        });

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
        }

        return $ids;
    }
}
