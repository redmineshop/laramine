<?php

namespace App\Domain\Issues\History;

/**
 * A journal header control. Presence only: these do not write rows.
 */
final readonly class JournalActionView
{
    /**
     * @param  list<JournalMenuItemView>  $menuItems
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon = null,
        public array $menuItems = [],
    ) {}
}
