<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Decodes GIF, JPEG, BMP, WebP, and AVIF through intervention/image on GD.
 *
 * The package is the converter. A format GD was built without, or a file GD
 * cannot read, produces no PNG.
 */
final class InterventionThumbnailDecoder implements ThumbnailDecoder
{
    public static function present(): bool
    {
        return function_exists('imagecreatefromstring') && class_exists(ImageManager::class);
    }

    public function supports(string $kind): bool
    {
        if (! self::present() || ! function_exists('imagetypes')) {
            return false;
        }

        $flag = match ($kind) {
            'gif' => IMG_GIF,
            'jpeg' => IMG_JPG,
            'bmp' => defined('IMG_BMP') ? IMG_BMP : 0,
            'webp' => defined('IMG_WEBP') ? IMG_WEBP : 0,
            'avif' => defined('IMG_AVIF') ? IMG_AVIF : 0,
            default => 0,
        };
        if ($flag === 0) {
            return false;
        }

        return (imagetypes() & $flag) !== 0;
    }

    public function toPng(string $bytes): ?string
    {
        if (! self::present() || $bytes === '') {
            return null;
        }

        try {
            ImageManager::gd()->read($bytes);
        } catch (Throwable) {
            return null;
        }

        $image = imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $rgb = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                if ($color === false) {
                    imagedestroy($image);

                    return null;
                }
                $rgb .= chr(($color >> 16) & 255).chr(($color >> 8) & 255).chr($color & 255);
            }
        }
        imagedestroy($image);

        try {
            return PngImage::encode($width, $height, $rgb);
        } catch (DomainException) {
            return null;
        }
    }
}
