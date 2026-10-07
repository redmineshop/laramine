<?php

namespace App\Domain\CustomFields;

/**
 * Bytes of one custom-field attachment the actor is allowed to read.
 */
final readonly class CustomFieldDownload
{
    public function __construct(
        public string $absolutePath,
        public string $filename,
        public ?string $contentType,
    ) {}
}
