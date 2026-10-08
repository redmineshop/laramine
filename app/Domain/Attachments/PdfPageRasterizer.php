<?php

namespace App\Domain\Attachments;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Rasterizes the first page of a PDF to an 8-bit RGB PNG.
 *
 * Ghostscript runs only when `convert` and `gs` both answer `-version` and
 * the file header looks like a PDF. A missing program, a rejected header, a
 * timeout, or a failed process yields no PNG.
 */
final class PdfPageRasterizer
{
    public function __construct(
        private readonly ThumbnailBinaries $binaries,
    ) {}

    public function firstPagePng(string $sourcePath): ?string
    {
        if (! $this->binaries->pdfReady() || ! PdfMagic::validFile($sourcePath)) {
            return null;
        }
        $directory = sys_get_temp_dir();
        $target = tempnam($directory, 'pdfpage');
        if ($target === false) {
            return null;
        }
        $pngPath = $target.'.png';
        @unlink($target);
        $process = new Process([
            $this->binaries->gsBinary(),
            '-q',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-sDEVICE=png16m',
            '-dFirstPage=1',
            '-dLastPage=1',
            '-r72',
            '-sOutputFile='.$pngPath,
            $sourcePath,
        ]);
        $process->setTimeout($this->binaries->timeoutSeconds());
        try {
            $process->run();
        } catch (Throwable) {
            $process->stop(0);
            $this->forget($pngPath);

            return null;
        }
        if (! $process->isSuccessful() || ! is_file($pngPath)) {
            $this->forget($pngPath);

            return null;
        }
        $bytes = file_get_contents($pngPath);
        $this->forget($pngPath);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return $bytes;
    }

    private function forget(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
