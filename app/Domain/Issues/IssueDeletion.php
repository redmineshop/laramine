<?php

namespace App\Domain\Issues;

use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Tree\NestedSet;
use App\Models\Attachment;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Watcher;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a leaf issue and closes its nested-set gap.
 *
 * An issue with subtasks is rejected. Time entries stay on the project
 * with a null issue. Repository history is not touched.
 */
final class IssueDeletion
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {}

    public function delete(User $actor, Issue $issue): void
    {
        $project = $issue->project;
        if ($project === null) {
            throw new DomainException('Issue has no project.');
        }
        if (! $this->permissions->allowed($actor, 'delete_issues', $project, $issue->tracker)) {
            throw new PermissionDeniedException('delete_issues');
        }

        $width = (int) $issue->rgt - (int) $issue->lft + 1;
        if ($width !== 2) {
            throw new DomainException('Issue has subtasks.');
        }

        DB::transaction(function () use ($issue): void {
            $locked = Issue::query()->whereKey($issue->id)->lockForUpdate()->first();
            if (! $locked instanceof Issue) {
                throw new DomainException('Issue does not exist.');
            }
            $lockedWidth = (int) $locked->rgt - (int) $locked->lft + 1;
            if ($lockedWidth !== 2) {
                throw new DomainException('Issue has subtasks.');
            }

            $id = (int) $locked->id;
            $journalIds = Journal::query()
                ->where('journalized_type', 'Issue')
                ->where('journalized_id', $id)
                ->pluck('id');
            if ($journalIds->isNotEmpty()) {
                JournalDetail::query()->whereIn('journal_id', $journalIds)->delete();
                Journal::query()->whereIn('id', $journalIds)->delete();
            }
            IssueRelation::query()->where('issue_from_id', $id)->orWhere('issue_to_id', $id)->delete();
            Watcher::query()->where('watchable_type', 'Issue')->where('watchable_id', $id)->delete();
            CustomValue::query()->where('customized_type', 'Issue')->where('customized_id', $id)->delete();
            Attachment::query()
                ->where('container_type', 'Issue')
                ->where('container_id', $id)
                ->update(['container_type' => null, 'container_id' => null]);
            TimeEntry::query()->where('issue_id', $id)->update(['issue_id' => null]);

            $scope = $locked->root_id === null ? [] : ['root_id' => (int) $locked->root_id];
            (new NestedSet('issues'))->closeGap((int) $locked->rgt, 2, $scope);
            $locked->delete();
        });
    }
}
