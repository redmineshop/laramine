<?php

namespace App\Domain\Attachments;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Probes the optional thumbnail programs.
 *
 * Every thumbnail needs ImageMagick `convert` to answer `-version`.
 * A PDF thumbnail also needs Ghostscript to answer `-version`. An empty
 * command string is not configured. A program that cannot be started, or
 * that exits with an error, is treated as missing.
 */
final class ThumbnailBinaries
{
    /**
     * @var array<string, bool>
     */
    private array $answers = [];

    public function pdfReady(): bool
    {
        return $this->convertAvailable() && $this->gsAvailable();
    }

    public function convertAvailable(): bool
    {
        return $this->answersVersion($this->command('redmine.imagemagick_convert_command', 'convert'));
    }

    public function gsAvailable(): bool
    {
        return $this->answersVersion($this->command('redmine.gs_command', 'gs'));
    }

    public function convertBinary(): string
    {
        return $this->command('redmine.imagemagick_convert_command', 'convert');
    }

    public function gsBinary(): string
    {
        return $this->command('redmine.gs_command', 'gs');
    }

    public function timeoutSeconds(): int
    {
        $value = config('redmine.thumbnails_generation_timeout');
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return 10;
    }

    private function command(string $key, string $fallback): string
    {
        $value = config($key);
        if (! is_string($value)) {
            return $fallback;
        }

        return trim($value);
    }

    private function answersVersion(string $binary): bool
    {
        if ($binary === '' || str_contains($binary, "\0")) {
            return false;
        }
        if (array_key_exists($binary, $this->answers)) {
            return $this->answers[$binary];
        }
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();
            $ok = $process->isSuccessful();
        } catch (Throwable) {
            $ok = false;
        }
        $this->answers[$binary] = $ok;

        return $ok;
    }
}
