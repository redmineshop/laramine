<?php

namespace App\Domain\TimeEntries;

use App\Models\Issue;
use App\Models\TimeEntry;

/**
 * Issue spent-hour sums.
 *
 * `spent` is the sum of `time_entries.hours` for that issue. `total` adds
 * the same sum for every descendant in the issue nested set. The sums are
 * not filtered by `time_entries_visibility`. Issue query columns keep that
 * filter on their own path.
 */
final class IssueSpentHours
{
    public function spent(Issue $issue): float
    {
        return $this->sum([(int) $issue->id]);
    }

    public function total(Issue $issue): float
    {
        $ids = Issue::query()
            ->where('root_id', $issue->root_id)
            ->where('lft', '>=', $issue->lft)
            ->where('rgt', '<=', $issue->rgt)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $this->sum(array_values($ids));
    }

    /**
     * @param  list<int>  $issueIds
     */
    private function sum(array $issueIds): float
    {
        if ($issueIds === []) {
            return 0.0;
        }

        return (float) TimeEntry::query()->whereIn('issue_id', $issueIds)->sum('hours');
    }
}
