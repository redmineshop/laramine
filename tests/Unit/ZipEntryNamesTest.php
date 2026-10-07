<?php

namespace Tests\Unit;

use App\Domain\Attachments\ZipEntryNames;
use PHPUnit\Framework\TestCase;

class ZipEntryNamesTest extends TestCase
{
    public function test_repeated_names_keep_the_extension_and_count_up(): void
    {
        $names = (new ZipEntryNames)->unique([
            'folder/note.txt',
            'note.txt',
            'note.txt',
            'plain',
            'plain',
        ]);

        $this->assertSame([
            'note.txt',
            'note(2).txt',
            'note(3).txt',
            'plain',
            'plain(2)',
        ], $names);
    }
}
