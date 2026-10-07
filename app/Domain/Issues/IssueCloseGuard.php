<?php

namespace App\Domain\Issues;

use App\Domain\WorkflowDeniedException;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;

/**
 * Close and reopen limits that sit beside the workflow matrix.
 *
 * An open descendant blocks close. An open issue that `blocks` this one
 * blocks close. A closed ancestor blocks reopen, and an open issue cannot
 * be placed under a closed parent. These checks apply to every actor.
 */
final class IssueCloseGuard
{
    public function assertCanClose(Issue $issue): void
    {
        if ($this->hasOpenDescendant($issue)) {
            throw new WorkflowDeniedException('This issue cannot be closed because it has at least one open subtask.');
        }

        if ($this->hasOpenBlocker($issue)) {
            throw new WorkflowDeniedException('This issue cannot be closed because it is blocked by at least one open issue.');
        }
    }

    public function assertCanReopen(?Issue $parent): void
    {
        $this->assertAncestorsOpen(
            $parent,
            'This issue cannot be reopened because its parent issue is closed.',
        );
    }

    public function assertOpenIssueUnder(Issue $parent): void
    {
        $this->assertAncestorsOpen(
            $parent,
            'An open issue cannot be attached to a closed parent issue.',
        );
    }

    private function assertAncestorsOpen(?Issue $start, string $message): void
    {
        $cursor = $start;
        $guard = 0;
        while ($cursor instanceof Issue && $guard < 64) {
            $guard++;
            $status = $cursor->relationLoaded('status') ? $cursor->status : IssueStatus::query()->find($cursor->status_id);
            if ($status instanceof IssueStatus && $status->is_closed) {
                throw new WorkflowDeniedException($message);
            }
            $parentId = $cursor->parent_id;
            if ($parentId === null) {
                return;
            }
            $next = $cursor->relationLoaded('parent') ? $cursor->parent : Issue::query()->find((int) $parentId);
            $cursor = $next instanceof Issue ? $next : null;
        }
    }

    private function hasOpenDescendant(Issue $issue): bool
    {
        $rootId = $issue->root_id;
        if ($rootId === null) {
            return false;
        }

        $open = $this->statusIds(false);
        if ($open === []) {
            return false;
        }

        return Issue::query()
            ->where('root_id', $rootId)
            ->where('lft', '>', (int) $issue->lft)
            ->where('rgt', '<', (int) $issue->rgt)
            ->whereIn('status_id', $open)
            ->exists();
    }

    private function hasOpenBlocker(Issue $issue): bool
    {
        $open = $this->statusIds(false);
        if ($open === []) {
            return false;
        }

        $fromIds = [];
        foreach (IssueRelation::query()
            ->where('issue_to_id', $issue->id)
            ->where('relation_type', 'blocks')
            ->pluck('issue_from_id') as $id) {
            if (is_numeric($id)) {
                $fromIds[] = (int) $id;
            }
        }
        if ($fromIds === []) {
            return false;
        }

        return Issue::query()
            ->whereIn('id', $fromIds)
            ->whereIn('status_id', $open)
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function statusIds(bool $closed): array
    {
        $ids = [];
        foreach (IssueStatus::query()->where('is_closed', $closed)->pluck('id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
