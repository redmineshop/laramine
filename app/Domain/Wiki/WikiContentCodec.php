<?php

namespace App\Domain\Wiki;

use App\Domain\DomainException;

/**
 * Packs wiki version text the way the `compression` column labels it.
 *
 * The payload is zlib (`gzcompress`), and the column value is `gzip`.
 */
final class WikiContentCodec
{
    /**
     * @return array{data: string, compression: string}
     */
    public static function pack(string $text): array
    {
        $packed = gzcompress($text, 9);
        if ($packed === false) {
            throw new DomainException('Wiki text could not be stored.');
        }

        return ['data' => $packed, 'compression' => 'gzip'];
    }

    public static function unpack(mixed $data, ?string $compression): string
    {
        $bytes = self::bytes($data);
        if ($bytes === '') {
            return '';
        }
        if ($compression === 'gzip') {
            $text = gzuncompress($bytes);
            if ($text === false) {
                throw new DomainException('Wiki version could not be read.');
            }

            return $text;
        }

        return $bytes;
    }

    private static function bytes(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }
        if (is_resource($data)) {
            $text = stream_get_contents($data);

            return is_string($text) ? $text : '';
        }

        return '';
    }
}
