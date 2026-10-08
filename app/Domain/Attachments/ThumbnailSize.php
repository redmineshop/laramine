<?php

namespace App\Domain\Attachments;

/**
 * Pixel edge for one thumbnail.
 *
 * A positive request is rounded up to a multiple of 50 and capped at 800.
 * A missing or non-positive request uses the configured size when that size
 * is positive, and 100 otherwise. The configured size is not rounded.
 */
final class ThumbnailSize
{
    public static function edge(?int $requested, int $configured): int
    {
        if ($requested !== null && $requested > 0) {
            $size = intdiv($requested + 49, 50) * 50;

            return $size > 800 ? 800 : $size;
        }
        if ($configured > 0) {
            return $configured;
        }

        return 100;
    }
}
