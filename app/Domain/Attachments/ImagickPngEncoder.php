<?php

namespace App\Domain\Attachments;

use Imagick;
use Throwable;

/**
 * Decodes one image through Imagick when GD cannot read it.
 *
 * AVIF is the format this path is for. A missing extension, an empty format
 * list, or a file Imagick rejects produces no PNG.
 */
final class ImagickPngEncoder
{
    public function supportsAvif(): bool
    {
        if (! class_exists(Imagick::class)) {
            return false;
        }
        try {
            $formats = Imagick::queryFormats('AVIF');
        } catch (Throwable) {
            return false;
        }

        return $formats !== [];
    }

    public function toPng(string $bytes): ?string
    {
        if (! class_exists(Imagick::class) || $bytes === '') {
            return null;
        }
        try {
            $image = new Imagick;
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);
            // Default PNG output collapses a flat image to 1-bit indexed, which
            // the in-process reader rejects. png24 stays 8-bit RGB.
            $image->setImageFormat('png24');
            $png = $image->getImageBlob();
            $image->clear();
        } catch (Throwable) {
            return null;
        }
        if ($png === '' || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        return $png;
    }
}
