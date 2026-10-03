<?php

namespace App\Domain\Issues\History;

/**
 * Escapes note text and turns a textile *emphasis* span into italics.
 *
 * Other textile marks are left as plain text.
 */
final class TextileEmphasis
{
    public function render(string $plain): string
    {
        $escaped = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rendered = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $escaped);

        return is_string($rendered) ? $rendered : $escaped;
    }
}
