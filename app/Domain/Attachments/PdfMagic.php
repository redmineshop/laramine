<?php

namespace App\Domain\Attachments;

/**
 * Accepts a file that starts like a PDF and rejects PostScript.
 *
 * The check is the first bytes only: `%PDF-`, or a UTF-8 BOM followed by
 * that same prefix. Anything else is not sent to Ghostscript.
 */
final class PdfMagic
{
    public static function valid(string $header): bool
    {
        return str_starts_with($header, '%PDF-')
            || str_starts_with($header, "\xEF\xBB\xBF%PDF-");
    }

    public static function validFile(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 8);
        fclose($handle);

        return is_string($header) && self::valid($header);
    }
}
