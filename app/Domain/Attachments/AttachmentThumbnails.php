<?php

namespace App\Domain\Attachments;

/**
 * Image filenames that can be shown as thumbnails on a journal.
 *
 * The decision uses the filename extension. Thumbnail bytes are not rendered.
 */
final class AttachmentThumbnails
{
    /**
     * @var list<string>
     */
    public const EXTENSIONS = ['bmp', 'gif', 'jpg', 'jpe', 'jpeg', 'png', 'webp'];

    public function isImage(string $filename): bool
    {
        $base = basename(str_replace('\\', '/', $filename));
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === strlen($base) - 1) {
            return false;
        }

        $extension = strtolower(substr($base, $dot + 1));

        return in_array($extension, self::EXTENSIONS, true);
    }
}
