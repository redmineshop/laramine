<?php

namespace App\Domain\Notifications;

use App\Domain\Issues\IssueJournalWriter;
use App\Models\Journal;
use App\Models\JournalDetail;

/**
 * Decides whether one issue journal produces mail for the enabled event list.
 *
 * `issue_updated` matches every journal that has a note or a detail.
 * The other issue events match only their own change. A journal with neither
 * a note nor a detail does not emit. News and the other unbuilt names never match a journal.
 */
final class JournalEventClassifier
{
    /**
     * @param  list<string>  $enabled
     */
    public function emits(Journal $journal, array $enabled): bool
    {
        if ($enabled === []) {
            return false;
        }

        $notes = $this->hasNotes($journal);
        $details = $this->details($journal);
        if (! $notes && $details === []) {
            return false;
        }

        if (in_array(NotifiedEventCatalog::ISSUE_UPDATED, $enabled, true)) {
            return true;
        }

        if ($notes && in_array(NotifiedEventCatalog::ISSUE_NOTE_ADDED, $enabled, true)) {
            return true;
        }

        foreach ($details as $detail) {
            $event = $this->detailEvent($detail);
            if ($event !== null && in_array($event, $enabled, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Specific events represented by the journal, excluding the `issue_updated` catch-all.
     *
     * @return list<string>
     */
    public function specific(Journal $journal): array
    {
        $events = [];
        if ($this->hasNotes($journal)) {
            $events[] = NotifiedEventCatalog::ISSUE_NOTE_ADDED;
        }
        foreach ($this->details($journal) as $detail) {
            $event = $this->detailEvent($detail);
            if ($event !== null && ! in_array($event, $events, true)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function hasNotes(Journal $journal): bool
    {
        return is_string($journal->notes) && trim($journal->notes) !== '';
    }

    /**
     * @return list<JournalDetail>
     */
    private function details(Journal $journal): array
    {
        $rows = $journal->relationLoaded('details')
            ? $journal->details
            : $journal->details()->get();

        $details = array_values($rows->all());
        usort($details, static fn (JournalDetail $left, JournalDetail $right): int => ((int) $left->id) <=> ((int) $right->id));

        return $details;
    }

    private function detailEvent(JournalDetail $detail): ?string
    {
        $property = (string) $detail->property;
        if ($property === IssueJournalWriter::PROPERTY_ATTACHMENT) {
            return NotifiedEventCatalog::ISSUE_ATTACHMENT_ADDED;
        }
        if ($property !== IssueJournalWriter::PROPERTY_ATTR) {
            return null;
        }

        return match ((string) $detail->prop_key) {
            'status_id' => NotifiedEventCatalog::ISSUE_STATUS_UPDATED,
            'assigned_to_id' => NotifiedEventCatalog::ISSUE_ASSIGNED_TO_UPDATED,
            'priority_id' => NotifiedEventCatalog::ISSUE_PRIORITY_UPDATED,
            'fixed_version_id' => NotifiedEventCatalog::ISSUE_FIXED_VERSION_UPDATED,
            default => null,
        };
    }
}
