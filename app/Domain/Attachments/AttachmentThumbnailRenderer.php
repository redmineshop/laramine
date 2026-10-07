<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;

/**
 * Builds a cached PNG thumbnail for an image attachment.
 *
 * The requested edge is used when it is between 1 and 800. Otherwise the
 * `thumbnails_size` setting is used, falling back to 100. The cache file is
 * reused until the attachment digest changes.
 */
final class AttachmentThumbnailRenderer
{
    public function __construct(
        private readonly AttachmentService $files,
        private readonly AttachmentThumbnails $images,
        private readonly SettingValue $settings,
    ) {}

    public function edge(?int $requested): int
    {
        if ($requested !== null && $requested >= 1 && $requested <= 800) {
            return $requested;
        }
        $stored = $this->settings->thumbnailsSize();
        if ($stored >= 1 && $stored <= 800) {
            return $stored;
        }

        return 100;
    }

    public function cachePath(Attachment $attachment, int $edge): string
    {
        return $this->files->diskPath('thumbnails/'.$attachment->id.'_'.$this->token($attachment).'_'.$edge.'.png');
    }

    public function render(Attachment $attachment, int $edge): string
    {
        if (! $this->images->isImage((string) $attachment->filename)) {
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
        $bytes = file_get_contents($source);
        if ($bytes === false || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw new DomainException('Thumbnail image could not be read.');
        }
        $png = PngImage::fit($bytes, $edge);
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
        $matches = glob($directory.'/'.$attachment->id.'_'.$token.'_*.png');
        if ($matches === false) {
            return;
        }
        foreach ($matches as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function token(Attachment $attachment): string
    {
        $digest = $attachment->digest;

        return is_string($digest) && preg_match('/^[A-Fa-f0-9]+$/', $digest) === 1 ? $digest : 'nodigest';
    }
}
