<?php

namespace App\Domain\Attachments;

/**
 * Turns a non-PNG image into a PNG the thumbnail cache can store.
 *
 * PNG files are decoded separately and do not use this converter. A missing
 * converter returns null, which the thumbnail route reports as no thumbnail.
 */
interface ThumbnailDecoder
{
    public function supports(string $kind): bool;

    public function toPng(string $bytes): ?string;
}
