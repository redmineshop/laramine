<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;

/**
 * Reads and writes 8-bit PNG images and scales one so its longest edge fits.
 *
 * Color types are gray, RGB, and RGBA. Indexed and interlaced images are
 * rejected. Scaling uses nearest-neighbor sampling and never enlarges.
 */
final class PngImage
{
    /**
     * @return array{0: int, 1: int}
     */
    public static function size(string $png): array
    {
        $image = self::decode($png);

        return [$image['width'], $image['height']];
    }

    public static function fit(string $png, int $edge): string
    {
        if ($edge < 1) {
            throw new DomainException('Thumbnail size is invalid.');
        }
        $image = self::decode($png);
        $width = $image['width'];
        $height = $image['height'];
        if ($width >= $height) {
            $destWidth = min($edge, $width);
            $destHeight = max(1, (int) round($height * $destWidth / $width));
        } else {
            $destHeight = min($edge, $height);
            $destWidth = max(1, (int) round($width * $destHeight / $height));
        }
        if ($destWidth === $width && $destHeight === $height) {
            return self::encode($width, $height, $image['rgb']);
        }

        $rgb = '';
        for ($y = 0; $y < $destHeight; $y++) {
            $sourceY = min($height - 1, (int) floor((($y + 0.5) * $height) / $destHeight));
            for ($x = 0; $x < $destWidth; $x++) {
                $sourceX = min($width - 1, (int) floor((($x + 0.5) * $width) / $destWidth));
                $index = ($sourceY * $width + $sourceX) * 3;
                $rgb .= substr($image['rgb'], $index, 3);
            }
        }

        return self::encode($destWidth, $destHeight, $rgb);
    }

    public static function encode(int $width, int $height, string $rgb): string
    {
        if ($width < 1 || $height < 1 || strlen($rgb) !== $width * $height * 3) {
            throw new DomainException('Thumbnail image is invalid.');
        }
        $stride = $width * 3;
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00".substr($rgb, $y * $stride, $stride);
        }
        $compressed = gzcompress($raw, 9);
        if ($compressed === false) {
            throw new DomainException('Thumbnail image could not be written.');
        }
        $ihdr = pack('NNC5', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $ihdr)
            .self::chunk('IDAT', $compressed)
            .self::chunk('IEND', '');
    }

    /**
     * @return array{width: int, height: int, rgb: string}
     */
    public static function decode(string $png): array
    {
        if (! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new DomainException('Thumbnail image could not be read.');
        }
        $offset = 8;
        $width = 0;
        $height = 0;
        $colorType = -1;
        $idat = '';
        $length = strlen($png);
        while ($offset + 8 <= $length) {
            $chunkLength = unpack('N', substr($png, $offset, 4));
            if ($chunkLength === false) {
                throw new DomainException('Thumbnail image could not be read.');
            }
            $size = $chunkLength[1];
            $type = substr($png, $offset + 4, 4);
            if ($offset + 12 + $size > $length) {
                throw new DomainException('Thumbnail image could not be read.');
            }
            $data = substr($png, $offset + 8, $size);
            $crc = substr($png, $offset + 8 + $size, 4);
            if (hash('crc32b', $type.$data, true) !== $crc) {
                throw new DomainException('Thumbnail image could not be read.');
            }
            if ($type === 'IHDR') {
                if ($size !== 13) {
                    throw new DomainException('Thumbnail image could not be read.');
                }
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                if ($header === false || $header['depth'] !== 8 || $header['compression'] !== 0 || $header['filter'] !== 0 || $header['interlace'] !== 0) {
                    throw new DomainException('Thumbnail image could not be read.');
                }
                $width = $header['width'];
                $height = $header['height'];
                $colorType = $header['color'];
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $offset += 12 + $size;
        }
        if ($width < 1 || $height < 1 || $idat === '' || ! in_array($colorType, [0, 2, 6], true)) {
            throw new DomainException('Thumbnail image could not be read.');
        }
        $inflated = gzuncompress($idat);
        if ($inflated === false) {
            throw new DomainException('Thumbnail image could not be read.');
        }
        $bytesPerPixel = match ($colorType) {
            0 => 1,
            2 => 3,
            6 => 4,
        };
        $stride = $width * $bytesPerPixel;
        $expected = ($stride + 1) * $height;
        if (strlen($inflated) !== $expected) {
            throw new DomainException('Thumbnail image could not be read.');
        }

        $rgb = '';
        $previous = str_repeat("\x00", $stride);
        $cursor = 0;
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($inflated[$cursor]);
            $cursor++;
            $scan = self::unfilter($filter, substr($inflated, $cursor, $stride), $previous, $bytesPerPixel);
            $cursor += $stride;
            $previous = $scan;
            $rgb .= self::toRgb($scan, $colorType, $width);
        }

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb];
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);
    }

    private static function unfilter(int $filter, string $scan, string $previous, int $bytesPerPixel): string
    {
        $stride = strlen($scan);
        $out = '';
        for ($index = 0; $index < $stride; $index++) {
            $raw = ord($scan[$index]);
            $left = $index >= $bytesPerPixel ? ord($out[$index - $bytesPerPixel]) : 0;
            $up = ord($previous[$index]);
            $upperLeft = $index >= $bytesPerPixel ? ord($previous[$index - $bytesPerPixel]) : 0;
            $value = match ($filter) {
                0 => $raw,
                1 => ($raw + $left) & 0xFF,
                2 => ($raw + $up) & 0xFF,
                3 => ($raw + intdiv($left + $up, 2)) & 0xFF,
                4 => ($raw + self::paeth($left, $up, $upperLeft)) & 0xFF,
                default => throw new DomainException('Thumbnail image could not be read.'),
            };
            $out .= chr($value);
        }

        return $out;
    }

    private static function paeth(int $left, int $up, int $upperLeft): int
    {
        $estimate = $left + $up - $upperLeft;
        $distanceLeft = abs($estimate - $left);
        $distanceUp = abs($estimate - $up);
        $distanceUpperLeft = abs($estimate - $upperLeft);
        if ($distanceLeft <= $distanceUp && $distanceLeft <= $distanceUpperLeft) {
            return $left;
        }
        if ($distanceUp <= $distanceUpperLeft) {
            return $up;
        }

        return $upperLeft;
    }

    private static function toRgb(string $scan, int $colorType, int $width): string
    {
        if ($colorType === 2) {
            return $scan;
        }
        $rgb = '';
        if ($colorType === 0) {
            for ($index = 0; $index < $width; $index++) {
                $gray = $scan[$index];
                $rgb .= $gray.$gray.$gray;
            }

            return $rgb;
        }
        for ($index = 0; $index < $width; $index++) {
            $rgb .= substr($scan, $index * 4, 3);
        }

        return $rgb;
    }
}
