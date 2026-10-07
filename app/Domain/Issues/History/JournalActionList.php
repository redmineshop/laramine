<?php

namespace App\Domain\Issues\History;

/**
 * Header controls for one journal on the History and Notes tabs.
 *
 * A note may show quote and edit. A property-only journal shows reaction and
 * more. The more menu always lists Copy link. Delete is listed only when the
 * note may be edited. The Property changes tab does not use this list.
 */
final class JournalActionList
{
    public const COPY_LINK = 'Copy link';

    public const DELETE = 'Delete';

    /**
     * @return list<JournalActionView>
     */
    public function forJournal(bool $hasNote, bool $canQuote, bool $canEdit, string $anchorHref): array
    {
        $reaction = new JournalActionView('reaction', 'thumbs-up');
        $more = new JournalActionView('more', '⋯', null, $this->menu($hasNote && $canEdit, $anchorHref));
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
    private function menu(bool $canDelete, string $anchorHref): array
    {
        $items = [
            new JournalMenuItemView('copy_link', self::COPY_LINK, $anchorHref),
        ];
        if ($canDelete) {
            $items[] = new JournalMenuItemView('delete', self::DELETE);
        }

        return $items;
    }
}
