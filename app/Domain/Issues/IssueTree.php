<?php

namespace App\Domain\Issues;

use App\Domain\Tree\NestedSet;
use App\Domain\Tree\TreeIntegrity;
use App\Domain\TreeException;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Subtask nested set. Each tree is scoped by root_id and starts at lft 1.
 */
final class IssueTree
{
    private NestedSet $sets;

    public function __construct()
    {
        $this->sets = new NestedSet('issues');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createRoot(array $attributes): Issue
    {
        return DB::transaction(function () use ($attributes): Issue {
            $attributes['parent_id'] = null;
            $attributes['root_id'] = null;
            $attributes['lft'] = 1;
            $attributes['rgt'] = 2;
            $issue = Issue::query()->create($attributes);
            $issue->root_id = $issue->id;
            $issue->save();

            return $issue->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createChild(Issue $parent, array $attributes): Issue
    {
        return DB::transaction(function () use ($parent, $attributes): Issue {
            $locked = $this->locked($parent->id);
            if ($locked->root_id === null) {
                throw new TreeException('Parent issue is missing its tree.');
            }
            $scope = ['root_id' => (int) $locked->root_id];
            $this->sets->lock($scope);
            $locked->refresh();
            $slot = $this->sets->allocateChildSlot((int) $locked->rgt, $scope);
            $attributes['parent_id'] = $locked->id;
            $attributes['root_id'] = (int) $locked->root_id;
            $attributes['lft'] = $slot['lft'];
            $attributes['rgt'] = $slot['rgt'];

            return Issue::query()->create($attributes)->refresh();
        });
    }

    public function move(Issue $issue, ?Issue $newParent): Issue
    {
        return DB::transaction(function () use ($issue, $newParent): Issue {
            $issue = $this->locked($issue->id);
            if ($newParent === null) {
                $this->extractRoot($issue);
            } else {
                $newParent = $this->locked($newParent->id);
                if ($issue->id === $newParent->id) {
                    throw new TreeException('A node cannot be moved under itself.');
                }
                if ((int) $issue->root_id === (int) $newParent->root_id) {
                    $this->sets->moveWithinScope($issue->id, $newParent->id, [
                        'root_id' => (int) $issue->root_id,
                    ]);
                } else {
                    $this->moveAcrossTrees($issue, $newParent);
                }
            }

            return $issue->refresh();
        });
    }

    public function assertConsistent(): void
    {
        TreeIntegrity::assertIssues();
    }

    private function extractRoot(Issue $issue): void
    {
        if ($issue->parent_id === null) {
            return;
        }
        if ($issue->root_id === null || $issue->lft === null || $issue->rgt === null) {
            throw new TreeException('Issue tree coordinates are missing.');
        }

        $oldRootId = (int) $issue->root_id;
        $lft = (int) $issue->lft;
        $rgt = (int) $issue->rgt;
        $width = $rgt - $lft + 1;
        $scope = ['root_id' => $oldRootId];
        $this->sets->park($lft, $rgt, $scope);
        $this->sets->closeGap($rgt, $width, $scope);
        $this->replant($oldRootId, $lft, 1, $issue->id);
        DB::table('issues')->where('id', $issue->id)->update([
            'parent_id' => null,
        ]);
    }

    private function moveAcrossTrees(Issue $issue, Issue $newParent): void
    {
        if ($issue->root_id === null || $newParent->root_id === null || $issue->lft === null || $issue->rgt === null || $newParent->rgt === null) {
            throw new TreeException('Issue tree coordinates are missing.');
        }

        $oldRootId = (int) $issue->root_id;
        $newRootId = (int) $newParent->root_id;
        $lft = (int) $issue->lft;
        $rgt = (int) $issue->rgt;
        $width = $rgt - $lft + 1;
        $this->sets->park($lft, $rgt, ['root_id' => $oldRootId]);
        $this->sets->closeGap($rgt, $width, ['root_id' => $oldRootId]);
        $newParent->refresh();
        $target = (int) $newParent->rgt;
        $this->sets->openGap($target, $width, ['root_id' => $newRootId]);
        $this->replant($oldRootId, $lft, $target, $newRootId);
        DB::table('issues')->where('id', $issue->id)->update([
            'parent_id' => $newParent->id,
        ]);
    }

    private function replant(int $oldRootId, int $oldLft, int $newLft, int $newRootId): void
    {
        $delta = $newLft - ($oldLft + NestedSet::PARK_OFFSET);
        DB::table('issues')
            ->where('root_id', $oldRootId)
            ->where('lft', '>=', NestedSet::PARK_OFFSET)
            ->update([
                'lft' => DB::raw('lft + ('.$delta.')'),
                'rgt' => DB::raw('rgt + ('.$delta.')'),
                'root_id' => $newRootId,
            ]);
    }

    private function locked(int $id): Issue
    {
        $issue = Issue::query()->whereKey($id)->lockForUpdate()->first();
        if ($issue === null) {
            throw new TreeException('Issue does not exist.');
        }

        return $issue;
    }
}
