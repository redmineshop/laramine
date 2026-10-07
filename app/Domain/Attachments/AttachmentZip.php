<?php

namespace App\Domain\Attachments;

/**
 * Zip bytes for "Download all files" on one issue or journal.
 */
final readonly class AttachmentZip
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
