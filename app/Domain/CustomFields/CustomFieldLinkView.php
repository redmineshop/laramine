<?php

namespace App\Domain\CustomFields;

/**
 * Stored link text and the URL a client may open.
 *
 * `url` is the formatted pattern when one is stored, otherwise the stored
 * string. This value is not requested by the server.
 */
final readonly class CustomFieldLinkView
{
    public function __construct(
        public string $value,
        public string $url,
    ) {}
}
