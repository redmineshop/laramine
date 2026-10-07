<?php

namespace App\Domain\Issues\History;

/**
 * One row in the journal more menu.
 *
 * Presence only. Choosing a row does not edit, delete, or zip a journal.
 * fragment is the copy-link target. Download all files leaves it null and
 * names the container instead.
 */
final readonly class JournalMenuItemView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $fragment = null,
        public ?string $containerType = null,
        public ?int $containerId = null,
    ) {}
}
