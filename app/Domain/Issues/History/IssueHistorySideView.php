<?php

namespace App\Domain\Issues\History;

/**
 * Spent time and associated revisions for one issue show.
 *
 * spentTimeVisible can be true while timeEntries is empty: the hours sum is
 * above zero, and the actor's time-entry visibility hid every row.
 */
final readonly class IssueHistorySideView
{
    /**
     * @param  list<HistoryTimeEntryView>  $timeEntries
     * @param  list<HistoryChangesetView>  $changesets
     */
    public function __construct(
        public bool $spentTimeVisible,
        public array $timeEntries,
        public array $changesets,
    ) {}
}
