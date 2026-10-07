<?php

namespace App\Domain\Wiki;

use App\Domain\DomainException;

/**
 * Stored wiki titles.
 *
 * Underscores become spaces, whitespace collapses, and the first character
 * is uppercased. The rest of the title is left as written.
 */
final class WikiTitle
{
    public static function titleize(string $title): string
    {
        $spaced = str_replace('_', ' ', $title);
        $collapsed = preg_replace('/\s+/u', ' ', $spaced);
        $trimmed = trim(is_string($collapsed) ? $collapsed : $spaced);
        if ($trimmed === '') {
            return '';
        }
        $first = mb_substr($trimmed, 0, 1);
        $rest = mb_substr($trimmed, 1);

        return mb_strtoupper($first).$rest;
    }

    public static function require(string $title): string
    {
        $stored = self::titleize($title);
        if ($stored === '') {
            throw new DomainException('Wiki page title is empty.');
        }
        if (strlen($stored) > 255) {
            throw new DomainException('Wiki page title is too long.');
        }

        return $stored;
    }
}
