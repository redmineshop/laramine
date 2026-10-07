<?php

namespace App\Domain\Queries;

/**
 * Turns a SQL number into the plain decimal text query totals already use.
 */
final class PlainDecimal
{
    public static function text(mixed $raw): string
    {
        if (is_int($raw)) {
            return (string) $raw;
        }

        if (is_float($raw)) {
            if (! is_finite($raw)) {
                return '0';
            }

            $raw = rtrim(rtrim(sprintf('%.10F', $raw), '0'), '.');
        }

        if (! is_string($raw)) {
            return '0';
        }

        $text = trim($raw);
        if ($text === '' || preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $text) !== 1) {
            return '0';
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        if (str_starts_with($text, '.')) {
            $text = '0'.$text;
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
        $fraction = rtrim($fraction, '0');
        $body = $fraction === '' ? $whole : $whole.'.'.$fraction;
        if ($body === '0') {
            return '0';
        }

        return $negative ? '-'.$body : $body;
    }
}
