<?php

namespace App\Domain\Issues\History;

/**
 * A journal header control on the issue show model.
 *
 * The object records which control is visible. It does not write.
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
