<?php

namespace App\Domain\Issues\History;

/**
 * Issue-show history and notes form markers for a later UI.
 *
 * historyEntries is the History list. Notes and Property changes are labels
 * only; their filters are capture debt and are not applied here.
 */
final readonly class IssueShowView
{
    /**
     * @param  list<string>  $historyTabLabels
     * @param  list<JournalEntryView>  $historyEntries
     */
    public function __construct(
        public bool $historyBlockVisible,
        public array $historyTabLabels,
        public array $historyEntries,
        public bool $notesFieldsetVisible,
        public bool $privateNotesCheckboxVisible,
        public bool $privateNotesChecked,
        public ?string $flashText,
        public ?string $flashTone,
    ) {}
}
