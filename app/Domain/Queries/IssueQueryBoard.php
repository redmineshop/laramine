<?php

namespace App\Domain\Queries;

use App\Models\Issue;
use App\Models\IssueStatus;

/**
 * Groups an already sorted issue list into status columns.
 */
final class IssueQueryBoard
{
    /**
     * @param  iterable<Issue>  $issues
     * @return list<IssueQueryBoardColumn>
     */
    public function columns(iterable $issues): array
    {
        /** @var array<int, list<int>> $idsByStatus */
        $idsByStatus = [];
        $seen = [];
        foreach ($issues as $issue) {
            $statusId = (int) $issue->status_id;
            if (! isset($idsByStatus[$statusId])) {
                $idsByStatus[$statusId] = [];
                $seen[] = $statusId;
            }
            $idsByStatus[$statusId][] = (int) $issue->id;
        }

        if ($seen === []) {
            return [];
        }

        $positions = [];
        $names = [];
        foreach (IssueStatus::query()->whereIn('id', $seen)->get() as $status) {
            $statusId = (int) $status->id;
            $positions[$statusId] = $this->position($status);
            $name = (string) $status->name;
            $names[$statusId] = $name !== '' ? $name : (string) $statusId;
        }

        $order = $seen;
        usort($order, static function (int $left, int $right) use ($positions): int {
            $byPosition = ($positions[$left] ?? PHP_INT_MAX) <=> ($positions[$right] ?? PHP_INT_MAX);
            if ($byPosition !== 0) {
                return $byPosition;
            }

            return $left <=> $right;
        });

        $columns = [];
        foreach ($order as $statusId) {
            $columns[] = new IssueQueryBoardColumn(
                $statusId,
                $names[$statusId] ?? (string) $statusId,
                $idsByStatus[$statusId],
            );
        }

        return $columns;
    }

    private function position(IssueStatus $status): int
    {
        $raw = $status->position;
        if (is_int($raw)) {
            return $raw;
        }

        return PHP_INT_MAX;
    }
}
