<?php

namespace App\Domain\Attachments;

/**
 * Converter stand-in used when GD cannot decode an image.
 *
 * The thumbnail route then has no image to send.
 */
final class AbsentThumbnailDecoder implements ThumbnailDecoder
{
    public function supports(string $kind): bool
    {
        return false;
    }

    public function toPng(string $bytes): ?string
    {
        return null;
    }
}
