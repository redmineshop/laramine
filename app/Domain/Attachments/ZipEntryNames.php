<?php

namespace App\Domain\Attachments;

/**
 * Unique file names inside a download-all zip.
 *
 * A repeated name keeps the extension and inserts `(2)`, `(3)`, and so on
 * before that extension. The container download route starts that count at 1.
 */
final class ZipEntryNames
{
    /**
     * @param  list<string>  $filenames
     * @return list<string>
     */
    public function unique(array $filenames, int $firstDuplicate = 2): array
    {
        $used = [];
        $names = [];
        foreach ($filenames as $filename) {
            $base = $this->base($filename);
            $candidate = $base;
            $counter = $firstDuplicate;
            while (isset($used[$candidate])) {
                $candidate = $this->withCounter($base, $counter);
                $counter++;
            }
            $used[$candidate] = true;
            $names[] = $candidate;
        }

        return $names;
    }

    private function base(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        if ($base === '' || $base === '.' || $base === '..') {
            return 'file';
        }

        return $base;
    }

    private function withCounter(string $filename, int $counter): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === 0) {
            return $filename.'('.$counter.')';
        }

        return substr($filename, 0, $dot).'('.$counter.')'.substr($filename, $dot);
    }
}
