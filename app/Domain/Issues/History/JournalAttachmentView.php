<?php

namespace App\Domain\Issues\History;

/**
 * One file attached to an issue or a journal on the history show model.
 *
 * thumbnailable is true when thumbnail display is enabled, ImageMagick
 * convert answers, and the filename is an image, or a PDF or Illustrator
 * file while Ghostscript also answers. thumbnailSize is the configured
 * pixel size then.
 */
final readonly class JournalAttachmentView
{
    public function __construct(
        public int $id,
        public string $filename,
        public ?string $contentType,
        public ?string $description,
        public int $filesize,
        public bool $thumbnailable,
        public ?int $thumbnailSize,
    ) {}
}
