<?php

namespace App\Domain\TextFormatting;

use Netcarver\Textile\Parser;

/**
 * Textile via the BSD PHP Textile parser.
 *
 * Restricted mode escapes raw HTML. Pre/code blocks are lifted out before
 * that escape so a language class can still be highlighted.
 */
final class TextileFormatter
{
    public function convert(string $text): string
    {
        $parser = new Parser;
        $parser->setDocumentType('html5');
        $parser->setRestricted(true);
        $parser->setLineWrap(false);
        $html = $parser->parse($text);

        return trim($html);
    }
}
