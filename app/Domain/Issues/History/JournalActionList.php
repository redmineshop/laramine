<?php

namespace App\Domain\Issues\History;

/**
 * Header controls for one journal on the History and Notes tabs.
 *
 * A note may show quote and edit. A property-only journal shows reaction and
 * more. The more menu lists Download all files when the caller passes that
 * item, then Copy link. Delete is listed only when the note may be edited.
 * The Property changes tab does not use this list.
 */
final class JournalActionList
{
    public const COPY_LINK = 'Copy link';

    public const DELETE = 'Delete';

    public const DOWNLOAD_ALL = 'Download all files';

    /**
     * @return list<JournalActionView>
     */
    public function forJournal(
        bool $hasNote,
        bool $canQuote,
        bool $canEdit,
        string $copyLink,
        ?JournalMenuItemView $downloadAll = null,
    ): array {
        $reaction = new JournalActionView('reaction', 'thumbs-up');
        $more = new JournalActionView('more', '⋯', null, $this->menu($hasNote && $canEdit, $copyLink, $downloadAll));
        if (! $hasNote) {
            return [$reaction, $more];
        }

        $actions = [$reaction];
        if ($canQuote) {
            $actions[] = new JournalActionView('quote', 'quote');
        }
        if ($canEdit) {
            $actions[] = new JournalActionView('edit', 'edit', 'pencil');
        }
        $actions[] = $more;

        return $actions;
    }

    /**
     * @return list<JournalMenuItemView>
     */
    private function menu(bool $canDelete, string $copyLink, ?JournalMenuItemView $downloadAll): array
    {
        $items = [];
        if ($downloadAll !== null) {
            $items[] = $downloadAll;
        }
        $items[] = new JournalMenuItemView('copy_link', self::COPY_LINK, $copyLink);
        if ($canDelete) {
            $items[] = new JournalMenuItemView('delete', self::DELETE);
        }

        return $items;
    }
}
