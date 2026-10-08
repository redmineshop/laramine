<?php

namespace App\Domain\Attachments;

/**
 * Filenames that can be shown as thumbnails.
 *
 * Images are decided from the extension. A PDF or Illustrator file is included
 * only when the caller already knows Ghostscript and ImageMagick are available.
 */
final class AttachmentThumbnails
{
    /**
     * @var list<string>
     */
    public const EXTENSIONS = ['avif', 'bmp', 'gif', 'jpg', 'jpe', 'jpeg', 'png', 'webp'];

    public function isImage(string $filename): bool
    {
        return in_array($this->extension($filename), self::EXTENSIONS, true);
    }

    public function isPdfLike(string $filename): bool
    {
        $extension = $this->extension($filename);

        return $extension === 'pdf' || $extension === 'ai';
    }

    public function canThumbnail(string $filename, bool $pdfToolsReady): bool
    {
        if ($this->isImage($filename)) {
            return true;
        }

        return $pdfToolsReady && $this->isPdfLike($filename);
    }

    private function extension(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === strlen($base) - 1) {
            return '';
        }

        return strtolower(substr($base, $dot + 1));
    }
}
