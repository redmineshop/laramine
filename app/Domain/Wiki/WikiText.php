<?php

namespace App\Domain\Wiki;

/**
 * Line diff and annotate for stored wiki text.
 *
 * The text is the raw column value. Diff and export do not render Textile or Markdown.
 */
final class WikiText
{
    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $parts = explode("\n", $text);
        if ($parts[array_key_last($parts)] === '') {
            array_pop($parts);
        }

        return $parts;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<array{op: string, text: string}>
     */
    public static function diff(array $left, array $right): array
    {
        $width = count($left);
        $height = count($right);
        $lengths = [];
        for ($row = 0; $row <= $width; $row++) {
            $lengths[$row] = array_fill(0, $height + 1, 0);
        }
        for ($row = $width - 1; $row >= 0; $row--) {
            for ($column = $height - 1; $column >= 0; $column--) {
                if ($left[$row] === $right[$column]) {
                    $lengths[$row][$column] = $lengths[$row + 1][$column + 1] + 1;
                } else {
                    $lengths[$row][$column] = max($lengths[$row + 1][$column], $lengths[$row][$column + 1]);
                }
            }
        }

        $ops = [];
        $row = 0;
        $column = 0;
        while ($row < $width && $column < $height) {
            if ($left[$row] === $right[$column]) {
                $ops[] = ['op' => 'equal', 'text' => $left[$row]];
                $row++;
                $column++;
            } elseif ($lengths[$row + 1][$column] >= $lengths[$row][$column + 1]) {
                $ops[] = ['op' => 'delete', 'text' => $left[$row]];
                $row++;
            } else {
                $ops[] = ['op' => 'insert', 'text' => $right[$column]];
                $column++;
            }
        }
        while ($row < $width) {
            $ops[] = ['op' => 'delete', 'text' => $left[$row]];
            $row++;
        }
        while ($column < $height) {
            $ops[] = ['op' => 'insert', 'text' => $right[$column]];
            $column++;
        }

        return $ops;
    }

    /**
     * @param  list<array{version: int, author_id: int|null, updated_on: string, text: string}>  $snapshots
     * @return list<array{line: string, version: int, author_id: int|null, updated_on: string}>
     */
    public static function annotate(array $snapshots, int $through): array
    {
        $selected = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot['version'] <= $through) {
                $selected[] = $snapshot;
            }
        }
        usort($selected, fn (array $left, array $right): int => $left['version'] <=> $right['version']);

        $lines = [];
        foreach ($selected as $index => $snapshot) {
            $next = self::lines($snapshot['text']);
            if ($index === 0) {
                foreach ($next as $line) {
                    $lines[] = [
                        'line' => $line,
                        'version' => $snapshot['version'],
                        'author_id' => $snapshot['author_id'],
                        'updated_on' => $snapshot['updated_on'],
                    ];
                }

                continue;
            }
            $previous = [];
            foreach ($lines as $line) {
                $previous[] = $line['line'];
            }
            $rebuilt = [];
            $cursor = 0;
            foreach (self::diff($previous, $next) as $op) {
                if ($op['op'] === 'equal') {
                    $rebuilt[] = $lines[$cursor];
                    $cursor++;
                } elseif ($op['op'] === 'delete') {
                    $cursor++;
                } else {
                    $rebuilt[] = [
                        'line' => $op['text'],
                        'version' => $snapshot['version'],
                        'author_id' => $snapshot['author_id'],
                        'updated_on' => $snapshot['updated_on'],
                    ];
                }
            }
            $lines = $rebuilt;
        }

        return $lines;
    }
}
