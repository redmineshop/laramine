<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;

/**
 * Builds a cached PNG thumbnail for an image or a PDF attachment.
 *
 * The requested edge is rounded up to a multiple of 50 and capped at 800.
 * A missing request uses `thumbnails_size`, then 100. The cache file is
 * `thumbnails/{digest}_{filesize}_{edge}.thumb` and is reused while it exists.
 * PNG is decoded in process. Other images go through the thumbnail converter.
 * A PDF is the first page from Ghostscript when `gs` and `convert` both
 * answer. A missing converter leaves no thumbnail.
 */
final class AttachmentThumbnailRenderer
{
    public function __construct(
        private readonly AttachmentService $files,
        private readonly AttachmentThumbnails $images,
        private readonly SettingValue $settings,
        private readonly ThumbnailDecoder $decoder,
        private readonly PdfPageRasterizer $pdfs,
        private readonly ThumbnailBinaries $binaries,
    ) {}

    public function edge(?int $requested): int
    {
        return ThumbnailSize::edge($requested, $this->settings->thumbnailsSize());
    }

    public function cachePath(Attachment $attachment, int $edge): string
    {
        return $this->files->diskPath('thumbnails/'.$this->token($attachment).'_'.$this->fileSize($attachment).'_'.$edge.'.thumb');
    }

    public function render(Attachment $attachment, int $edge): string
    {
        $filename = (string) $attachment->filename;
        if (! $this->images->canThumbnail($filename, $this->binaries->pdfReady())) {
            throw new DomainException('Attachment is not an image.');
        }
        $source = $this->files->absolutePath($attachment);
        if (! is_file($source)) {
            throw new DomainException('Attachment file is not stored.');
        }
        $cache = $this->cachePath($attachment, $edge);
        if (is_file($cache)) {
            return $cache;
        }
        $png = $this->pngBytes($filename, $source, $edge);
        if ($png === null) {
            throw new DomainException('Thumbnail image could not be read.');
        }
        $directory = dirname($cache);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new DomainException('Thumbnail directory could not be created.');
        }
        if (file_put_contents($cache, $png) === false) {
            throw new DomainException('Thumbnail image could not be written.');
        }

        return $cache;
    }

    public function forget(Attachment $attachment): void
    {
        $token = $this->token($attachment);
        $directory = $this->files->diskPath('thumbnails');
        if (! is_dir($directory)) {
            return;
        }
        $matches = glob($directory.'/'.$token.'_'.$this->fileSize($attachment).'_*.thumb');
        if ($matches === false) {
            return;
        }
        foreach ($matches as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function pngBytes(string $filename, string $source, int $edge): ?string
    {
        if ($this->images->isPdfLike($filename)) {
            $raster = $this->pdfs->firstPagePng($source);
            if ($raster === null) {
                return null;
            }

            try {
                return PngImage::fit($raster, $edge);
            } catch (DomainException) {
                return null;
            }
        }
        $bytes = file_get_contents($source);
        if ($bytes === false) {
            return null;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            try {
                return PngImage::fit($bytes, $edge);
            } catch (DomainException) {
                return null;
            }
        }
        $converted = $this->decoder->toPng($bytes);
        if ($converted === null) {
            return null;
        }
        try {
            return PngImage::fit($converted, $edge);
        } catch (DomainException) {
            return null;
        }
    }

    private function token(Attachment $attachment): string
    {
        $digest = $attachment->digest;

        return is_string($digest) && preg_match('/^[A-Fa-f0-9]+$/', $digest) === 1 ? $digest : 'nodigest';
    }

    private function fileSize(Attachment $attachment): int
    {
        $size = $attachment->filesize;
        if (is_int($size)) {
            return $size;
        }
        if (is_string($size) && preg_match('/^\d+$/', $size) === 1) {
            return (int) $size;
        }

        return 0;
    }
}
