<?php

namespace App\Domain\Attachments;

/**
 * Bytes a controller can send for one attachment or thumbnail.
 */
final class AttachmentDownload
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $filename,
        public readonly string $contentType,
        public readonly string $disposition,
    ) {}
}
