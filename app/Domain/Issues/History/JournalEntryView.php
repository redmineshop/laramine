<?php

namespace App\Domain\Issues\History;

/**
 * One journal row in display order.
 *
 * anchorLabel is #n for that order. It is not journals.id.
 * anchorHref is #note-n for the same visible index.
 * hasNote and hasDetails describe the stored journal. hasThumbnails is true
 * when thumbnail display is on and a file name is an image. A Property changes
 * copy keeps those flags, clears the note text, and keeps only reaction.
 */
final readonly class JournalEntryView
{
    /**
     * @param  list<JournalPropertyLine>  $propertyChanges
     * @param  list<JournalActionView>  $actions
     * @param  list<JournalAttachmentView>  $attachments
     */
    public function __construct(
        public int $journalId,
        public string $anchorLabel,
        public string $anchorHref,
        public bool $hasNote,
        public ?string $noteText,
        public ?string $noteHtml,
        public array $propertyChanges,
        public array $actions,
        public bool $privateNotes,
        public bool $hasDetails,
        public bool $hasThumbnails,
        public array $attachments,
    ) {}
}
