<?php

namespace App\Domain\Documents;

/**
 * Groups project documents the way the 7.0.1 documents index does.
 *
 * Rows must already be in id order. Category, title, and author groups keep
 * that order and the first time each key appears. Date groups sort by
 * `updated_on` descending, then id descending. An unknown sort is category.
 * Author grouping omits a row whose `author_id` is null.
 *
 * @phpstan-type DocumentRow array{id: int, title: string, category_id: int, created_on: string, updated_on: string, author_id: int|null}
 */
final class DocumentGroups
{
    /**
     * @param  list<DocumentRow>  $documents
     * @return list<array{key: string, document_ids: list<int>}>
     */
    public static function group(array $documents, string $sortBy): array
    {
        if ($sortBy === 'date') {
            $rows = $documents;
            usort($rows, function (array $left, array $right): int {
                $byDate = $right['updated_on'] <=> $left['updated_on'];
                if ($byDate !== 0) {
                    return $byDate;
                }

                return $right['id'] <=> $left['id'];
            });

            return self::buckets($rows, 'date');
        }

        if ($sortBy === 'title') {
            return self::buckets($documents, 'title');
        }

        if ($sortBy === 'author') {
            $withAuthor = [];
            foreach ($documents as $row) {
                if ($row['author_id'] !== null) {
                    $withAuthor[] = $row;
                }
            }

            return self::buckets($withAuthor, 'author');
        }

        return self::buckets($documents, 'category');
    }

    /**
     * @param  list<DocumentRow>  $rows
     * @return list<array{key: string, document_ids: list<int>}>
     */
    private static function buckets(array $rows, string $sort): array
    {
        $order = [];
        $ids = [];
        foreach ($rows as $row) {
            $bucket = self::key($row, $sort);
            if (! isset($ids[$bucket])) {
                $order[] = $bucket;
                $ids[$bucket] = [];
            }
            $ids[$bucket][] = $row['id'];
        }

        $groups = [];
        foreach ($order as $bucket) {
            $groups[] = [
                'key' => $bucket,
                'document_ids' => $ids[$bucket],
            ];
        }

        return $groups;
    }

    /**
     * @param  DocumentRow  $row
     */
    private static function key(array $row, string $sort): string
    {
        if ($sort === 'date') {
            return 'date:'.substr($row['updated_on'], 0, 10);
        }

        if ($sort === 'title') {
            $first = mb_substr($row['title'], 0, 1, 'UTF-8');

            return 'title:'.mb_strtoupper($first, 'UTF-8');
        }

        if ($sort === 'author') {
            $authorId = $row['author_id'];

            return 'author:'.(string) ($authorId ?? 0);
        }

        return 'category:'.$row['category_id'];
    }
}
