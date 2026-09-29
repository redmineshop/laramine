<?php

namespace App\Domain\Tree;

use App\Domain\TreeException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Modified preorder tree maintenance for one table.
 *
 * A subtree is parked at a high lft/rgt offset, the gap it left is closed,
 * a gap is opened at the destination, and the subtree is shifted back.
 * Projects use an empty scope (one forest). Issue moves pass `root_id`.
 */
final class NestedSet
{
    /**
     * Parked coordinates stay below the signed-integer maximum (2_147_483_647)
     * when added to a normal lft/rgt.
     */
    public const PARK_OFFSET = 1_000_000_000;

    public function __construct(private readonly string $table) {}

    /**
     * @param  array<string, int>  $scope
     */
    public function lock(array $scope = []): void
    {
        $this->query($scope)->lockForUpdate()->get(['id']);
    }

    /**
     * @param  array<string, int>  $scope
     * @return array{lft: int, rgt: int}
     */
    public function nextRootBounds(array $scope = []): array
    {
        $max = $this->query($scope)->where('rgt', '<', self::PARK_OFFSET)->max('rgt');
        $lft = (is_numeric($max) ? (int) $max : 0) + 1;

        return ['lft' => $lft, 'rgt' => $lft + 1];
    }

    /**
     * @param  array<string, int>  $scope
     * @return array{lft: int, rgt: int}
     */
    public function allocateChildSlot(int $parentRgt, array $scope = []): array
    {
        $this->openGap($parentRgt, 2, $scope);

        return ['lft' => $parentRgt, 'rgt' => $parentRgt + 1];
    }

    /**
     * Move a node under `$newParentId` inside one scope. Null makes it a root
     * of that same scope (project forest only; issue trees use IssueTree).
     *
     * @param  array<string, int>  $scope
     */
    public function moveWithinScope(int $nodeId, ?int $newParentId, array $scope = []): void
    {
        $node = $this->query($scope)->where('id', $nodeId)->first();
        if (! is_object($node)) {
            throw new TreeException('Node '.$nodeId.' was not found in '.$this->table.'.');
        }

        $lft = $this->requireInt($node, 'lft');
        $rgt = $this->requireInt($node, 'rgt');
        $width = $rgt - $lft + 1;
        $currentParent = $this->nullableInt($node, 'parent_id');

        if ($currentParent === $newParentId) {
            return;
        }

        if ($newParentId !== null) {
            $this->assertValidParent($nodeId, $lft, $rgt, $newParentId, $scope);
        }

        $this->park($lft, $rgt, $scope);
        $this->closeGap($rgt, $width, $scope);
        $target = $this->destination($newParentId, $scope);
        $this->openGap($target, $width, $scope);
        $this->unpark($lft, $target, $scope);
        $this->query($scope)->where('id', $nodeId)->update([
            'parent_id' => $newParentId,
        ]);
    }

    /**
     * @param  array<string, int>  $scope
     */
    private function destination(?int $newParentId, array $scope): int
    {
        if ($newParentId === null) {
            $max = $this->query($scope)->where('rgt', '<', self::PARK_OFFSET)->max('rgt');

            return (is_numeric($max) ? (int) $max : 0) + 1;
        }

        $parent = $this->query($scope)->where('id', $newParentId)->first();
        if (! is_object($parent)) {
            throw new TreeException('Target parent disappeared during the move.');
        }

        return $this->requireInt($parent, 'rgt');
    }

    /**
     * @param  array<string, int>  $scope
     */
    private function assertValidParent(int $nodeId, int $lft, int $rgt, int $newParentId, array $scope): void
    {
        if ($newParentId === $nodeId) {
            throw new TreeException('A node cannot be moved under itself.');
        }

        $parent = $this->query($scope)->where('id', $newParentId)->first();
        if (! is_object($parent)) {
            throw new TreeException('Target parent is not in this tree.');
        }

        $parentLft = $this->requireInt($parent, 'lft');
        $parentRgt = $this->requireInt($parent, 'rgt');
        if ($parentLft >= $lft && $parentRgt <= $rgt) {
            throw new TreeException('A node cannot be moved under its descendant.');
        }
    }

    /**
     * @param  array<string, int>  $scope
     */
    public function openGap(int $at, int $width, array $scope = []): void
    {
        $this->query($scope)->where('lft', '>=', $at)->where('lft', '<', self::PARK_OFFSET)->increment('lft', $width);
        $this->query($scope)->where('rgt', '>=', $at)->where('rgt', '<', self::PARK_OFFSET)->increment('rgt', $width);
    }

    /**
     * @param  array<string, int>  $scope
     */
    public function park(int $lft, int $rgt, array $scope = []): void
    {
        $offset = self::PARK_OFFSET;
        $this->query($scope)->whereBetween('lft', [$lft, $rgt])->update([
            'lft' => DB::raw('lft + '.$offset),
            'rgt' => DB::raw('rgt + '.$offset),
        ]);
    }

    /**
     * @param  array<string, int>  $scope
     */
    public function closeGap(int $rgt, int $width, array $scope = []): void
    {
        $this->query($scope)->where('lft', '>', $rgt)->where('lft', '<', self::PARK_OFFSET)->decrement('lft', $width);
        $this->query($scope)->where('rgt', '>', $rgt)->where('rgt', '<', self::PARK_OFFSET)->decrement('rgt', $width);
    }

    /**
     * @param  array<string, int>  $scope
     */
    public function unpark(int $oldLft, int $newLft, array $scope = []): void
    {
        $delta = $newLft - ($oldLft + self::PARK_OFFSET);
        $this->query($scope)->where('lft', '>=', self::PARK_OFFSET)->update([
            'lft' => DB::raw('lft + ('.$delta.')'),
            'rgt' => DB::raw('rgt + ('.$delta.')'),
        ]);
    }

    /**
     * @param  array<string, int>  $scope
     */
    private function query(array $scope): Builder
    {
        $query = DB::table($this->table);
        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query;
    }

    private function requireInt(object $row, string $column): int
    {
        $value = $row->{$column} ?? null;
        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw new TreeException('Column '.$column.' on '.$this->table.' is missing or not numeric.');
        }

        return (int) $value;
    }

    private function nullableInt(object $row, string $column): ?int
    {
        $value = $row->{$column} ?? null;
        if ($value === null) {
            return null;
        }

        return $this->requireInt($row, $column);
    }
}
