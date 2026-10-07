<?php

namespace App\Domain\Issues\History;

/**
 * One row in the journal more menu.
 *
 * Presence only. Choosing a row does not edit or delete a journal.
 */
final readonly class JournalMenuItemView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $fragment = null,
    ) {}
}
