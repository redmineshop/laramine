<?php

namespace App\Domain\Issues\History;

/**
 * Header controls for one journal.
 *
 * A note shows reaction, quote, edit, and more. A property-only journal shows
 * reaction and more. Edit permission does not change this list.
 */
final class JournalActionList
{
    /**
     * @return list<JournalActionView>
     */
    public function forJournal(bool $hasNote): array
    {
        $reaction = new JournalActionView('reaction', 'thumbs-up');
        $more = new JournalActionView('more', '⋯');
        if (! $hasNote) {
            return [$reaction, $more];
        }

        return [
            $reaction,
            new JournalActionView('quote', 'quote'),
            new JournalActionView('edit', 'edit', 'pencil'),
            $more,
        ];
    }
}
