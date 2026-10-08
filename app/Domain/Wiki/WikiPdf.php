<?php

namespace App\Domain\Wiki;

/**
 * One-page text PDF of a wiki title and stored lines.
 *
 * Core wiki export includes PDF without an optional image gem. This writer
 * keeps the title and the stored text. It does not copy PDF page geometry.
 */
final class WikiPdf
{
    /**
     * @param  list<string>  $lines
     */
    public function render(string $heading, array $lines): string
    {
        $commands = ['BT /F1 11 Tf 40 800 Td ('.$this->escape($heading).') Tj'];
        foreach ($lines as $line) {
            $commands[] = '0 -14 Td ('.$this->escape($line).') Tj';
        }
        $commands[] = 'ET';
        $stream = implode("\n", $commands);
        $objects = [
            "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n",
            "2 0 obj << /Type /Pages /Count 1 /Kids [3 0 R] >> endobj\n",
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n",
            '4 0 obj << /Length '.strlen($stream)." >> stream\n".$stream."\nendstream endobj\n",
            "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function escape(string $text): string
    {
        $latin = preg_replace('/[^\x20-\x7E]/', '?', $text);
        $safe = is_string($latin) ? $latin : '';

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
    }
}
