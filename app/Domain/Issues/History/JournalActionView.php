<?php

namespace App\Domain\Issues\History;

/**
 * A journal header control. Presence only: these do not run an action.
 */
final readonly class JournalActionView
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $icon = null,
    ) {}
}
