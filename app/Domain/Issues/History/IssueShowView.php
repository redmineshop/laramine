<?php

namespace App\Domain\Issues\History;

/**
 * Issue-show history and notes form markers for a later UI.
 *
 * historyEntries is the History list. notesEntries keeps journals with note
 * text or a thumbnail. propertyChangeEntries keeps journals that have a
 * visible detail line, with the note body omitted and only the reaction
 * control left. timeEntries
 * and changesets fill the Spent time and Associated revisions tabs.
 */
final readonly class IssueShowView
{
    /**
     * @param  list<string>  $historyTabLabels
     * @param  list<JournalEntryView>  $historyEntries
     * @param  list<JournalEntryView>  $notesEntries
     * @param  list<JournalEntryView>  $propertyChangeEntries
     * @param  list<JournalAttachmentView>  $issueAttachments
     * @param  list<HistoryTimeEntryView>  $timeEntries
     * @param  list<HistoryChangesetView>  $changesets
     */
    public function __construct(
        public bool $historyBlockVisible,
        public array $historyTabLabels,
        public array $historyEntries,
        public array $notesEntries,
        public array $propertyChangeEntries,
        public bool $notesFieldsetVisible,
        public bool $privateNotesCheckboxVisible,
        public bool $privateNotesChecked,
        public ?string $flashText,
        public ?string $flashTone,
        public array $issueAttachments,
        public ?JournalMenuItemView $issueDownloadAll,
        public array $timeEntries,
        public array $changesets,
    ) {}
}
