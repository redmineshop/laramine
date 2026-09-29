<?php

namespace App\Domain\CustomFields\Formats;

final class StringFormat extends BoundedTextFormat
{
    public function key(): string
    {
        return 'string';
    }

    public function queryFilterType(): string
    {
        return 'string';
    }
}
