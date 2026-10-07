<?php

namespace App\Domain\TextFormatting;

/**
 * Plain text when text_formatting is empty.
 *
 * HTML is escaped, blank lines split paragraphs, and a single newline becomes
 * a break. URLs and mailto addresses are linked. Redmine links are applied later.
 */
final class PlainFormatter
{
    public function convert(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            return '';
        }
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = $this->autoLink($escaped);
        $blocks = preg_split("/\n{2,}/", $linked);
        if ($blocks === false) {
            return '';
        }
        $html = [];
        foreach ($blocks as $block) {
            if ($block === '') {
                continue;
            }
            $html[] = '<p>'.str_replace("\n", "<br />\n", $block).'</p>';
        }

        return implode("\n\n", $html);
    }

    private function autoLink(string $escaped): string
    {
        $linked = preg_replace(
            '/(?<!["\'>])(https?:\/\/[^\s<]+)/',
            '<a href="$1">$1</a>',
            $escaped,
        );
        if (! is_string($linked)) {
            $linked = $escaped;
        }
        $mailed = preg_replace(
            '/(?<!["\'>])([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i',
            '<a href="mailto:$1">$1</a>',
            $linked,
        );

        return is_string($mailed) ? $mailed : $linked;
    }
}
