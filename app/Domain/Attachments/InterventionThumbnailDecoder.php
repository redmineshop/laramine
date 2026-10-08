<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;
use Intervention\Image\ImageManager;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Decodes GIF, JPEG, BMP, WebP, and AVIF into a PNG.
 *
 * GD reads the formats it was built with. AVIF falls through to Imagick, then
 * to ImageMagick `convert`, when those are present. A file none of them can
 * read produces no PNG.
 */
final class InterventionThumbnailDecoder implements ThumbnailDecoder
{
    public function __construct(
        private readonly ImagickPngEncoder $imagick,
        private readonly ThumbnailBinaries $binaries,
    ) {}

    public static function present(): bool
    {
        return function_exists('imagecreatefromstring') && class_exists(ImageManager::class);
    }

    public function supports(string $kind): bool
    {
        if ($kind === 'avif') {
            return $this->gdSupports('avif') || $this->imagick->supportsAvif() || $this->binaries->convertAvailable();
        }

        return $this->gdSupports($kind);
    }

    public function toPng(string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $gd = $this->gdPng($bytes);
        if ($gd !== null) {
            return $gd;
        }
        $imagick = $this->imagick->toPng($bytes);
        if ($imagick !== null) {
            return $imagick;
        }
        if ($this->looksLikeAvif($bytes)) {
            return $this->convertAvif($bytes);
        }

        return null;
    }

    private function gdSupports(string $kind): bool
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

    private function gdPng(string $bytes): ?string
    {
        if (! self::present()) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
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

    private function looksLikeAvif(string $bytes): bool
    {
        if (strlen($bytes) < 12 || substr($bytes, 4, 4) !== 'ftyp') {
            return false;
        }
        $brand = substr($bytes, 8, 8);

        return str_contains($brand, 'avif') || str_contains($brand, 'avis');
    }

    private function convertAvif(string $bytes): ?string
    {
        if (! $this->binaries->convertAvailable()) {
            return null;
        }
        $source = tempnam(sys_get_temp_dir(), 'avifsrc');
        if ($source === false) {
            return null;
        }
        $pngPath = $source.'.png';
        if (file_put_contents($source, $bytes) === false) {
            $this->forget($source);

            return null;
        }
        $process = new Process([
            $this->binaries->convertBinary(),
            $source,
            'png24:'.$pngPath,
        ]);
        $process->setTimeout($this->binaries->timeoutSeconds());
        try {
            $process->run();
        } catch (Throwable) {
            $process->stop(0);
            $this->forget($source);
            $this->forget($pngPath);

            return null;
        }
        if (! $process->isSuccessful() || ! is_file($pngPath)) {
            $this->forget($source);
            $this->forget($pngPath);

            return null;
        }
        $png = file_get_contents($pngPath);
        $this->forget($source);
        $this->forget($pngPath);
        if (! is_string($png) || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        return $png;
    }

    private function forget(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
