<?php

namespace App\Domain\CustomFields\Formats;

final class TextFormat extends BoundedTextFormat
{
    public function key(): string
    {
        return 'text';
    }

    public function queryFilterType(): string
    {
        return 'text';
    }
}
