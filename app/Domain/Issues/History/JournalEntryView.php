<?php

namespace App\Domain\Issues\History;

/**
 * One journal row in display order.
 *
 * anchorLabel is #n for that order. It is not journals.id, and it has no href.
 */
final readonly class JournalEntryView
{
    /**
     * @param  list<JournalPropertyLine>  $propertyChanges
     * @param  list<JournalActionView>  $actions
     */
    public function __construct(
        public int $journalId,
        public string $anchorLabel,
        public bool $hasNote,
        public ?string $noteText,
        public ?string $noteHtml,
        public array $propertyChanges,
        public array $actions,
        public bool $privateNotes,
    ) {}
}
