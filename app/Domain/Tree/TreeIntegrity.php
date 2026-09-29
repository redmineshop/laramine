<?php

namespace App\Domain\Tree;

use App\Domain\TreeException;
use Illuminate\Support\Facades\DB;

/**
 * Checks that lft/rgt pairs match parent_id for one forest or one issue tree.
 */
final class TreeIntegrity
{
    public static function assertProjects(): void
    {
        $rows = [];
        foreach (DB::table('projects')->orderBy('lft')->get(['id', 'parent_id', 'lft', 'rgt']) as $row) {
            $rows[] = $row;
        }
        self::assertRows('projects', $rows);
    }

    public static function assertIssues(): void
    {
        $rows = DB::table('issues')->orderBy('lft')->get(['id', 'parent_id', 'root_id', 'lft', 'rgt']);
        if ($rows->isEmpty()) {
            return;
        }

        /** @var array<int, list<object>> $byRoot */
        $byRoot = [];
        foreach ($rows as $row) {
            $rootId = self::nullableInt($row, 'root_id');
            if ($rootId === null) {
                throw new TreeException('Issue '.self::requireInt($row, 'id').' is missing root_id.');
            }
            $byRoot[$rootId][] = $row;
        }

        foreach ($byRoot as $rootId => $group) {
            self::assertRows('issues', $group);
            $root = null;
            foreach ($group as $row) {
                if (self::requireInt($row, 'id') === $rootId) {
                    $root = $row;
                }
            }
            if ($root === null) {
                throw new TreeException('Issue tree root '.$rootId.' is missing.');
            }
            if (self::nullableInt($root, 'parent_id') !== null) {
                throw new TreeException('Issue root '.$rootId.' has a parent.');
            }
            if (self::requireInt($root, 'lft') !== 1) {
                throw new TreeException('Issue root '.$rootId.' does not start at lft 1.');
            }
        }
    }

    /**
     * @param  list<object>  $rows
     */
    private static function assertRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $count = count($rows);
        $seen = [];
        $numbers = [];

        foreach ($rows as $row) {
            $id = self::requireInt($row, 'id');
            $lft = self::requireInt($row, 'lft');
            $rgt = self::requireInt($row, 'rgt');
            if ($lft >= NestedSet::PARK_OFFSET || $rgt >= NestedSet::PARK_OFFSET) {
                throw new TreeException($table.' node '.$id.' is still parked.');
            }
            if ($rgt <= $lft) {
                throw new TreeException($table.' node '.$id.' has rgt <= lft.');
            }
            if (($rgt - $lft) % 2 === 0) {
                throw new TreeException($table.' node '.$id.' has an even lft/rgt span.');
            }
            if (isset($seen[$lft]) || isset($seen[$rgt])) {
                throw new TreeException($table.' has a duplicate lft/rgt value.');
            }
            $seen[$lft] = true;
            $seen[$rgt] = true;
            $numbers[] = $lft;
            $numbers[] = $rgt;

            $parentId = self::nullableInt($row, 'parent_id');
            $tightestId = null;
            $tightestLft = null;
            foreach ($rows as $candidate) {
                $candidateLft = self::requireInt($candidate, 'lft');
                $candidateRgt = self::requireInt($candidate, 'rgt');
                if ($candidateLft < $lft && $candidateRgt > $rgt) {
                    if ($tightestLft === null || $candidateLft > $tightestLft) {
                        $tightestLft = $candidateLft;
                        $tightestId = self::requireInt($candidate, 'id');
                    }
                }
            }
            if ($tightestId !== $parentId) {
                throw new TreeException($table.' node '.$id.' parent_id does not match the nested set.');
            }
        }

        sort($numbers);
        $expected = range(1, $count * 2);
        if ($numbers !== $expected) {
            throw new TreeException($table.' lft/rgt values are not the contiguous range 1..'.($count * 2).'.');
        }
    }

    private static function requireInt(object $row, string $column): int
    {
        $value = self::nullableInt($row, $column);
        if ($value === null) {
            throw new TreeException('Column '.$column.' is missing.');
        }

        return $value;
    }

    private static function nullableInt(object $row, string $column): ?int
    {
        $value = $row->{$column} ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw new TreeException('Column '.$column.' is not numeric.');
        }

        return (int) $value;
    }
}
