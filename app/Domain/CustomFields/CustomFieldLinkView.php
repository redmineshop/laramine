<?php

namespace App\Domain\CustomFields;

/**
 * Stored link text and the URL a client fetches.
 *
 * `url` is the formatted pattern when one is stored. Otherwise a value that
 * already starts with `scheme://` is kept, and every other value is prefixed
 * with `http://`. This value is not requested by the server.
 */
final readonly class CustomFieldLinkView
{
    public function __construct(
        public string $value,
        public string $url,
    ) {}
}
