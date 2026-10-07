<?php

namespace Tests\Unit;

use App\Domain\Issues\JournalQuoteText;
use PHPUnit\Framework\TestCase;

class JournalQuoteTextTest extends TestCase
{
    public function test_quote_names_the_author_and_prefixes_each_line(): void
    {
        $quotes = new JournalQuoteText;
        $notes = "Check the gate.\n<pre>\nsecret\n</pre>\nThen ship.";

        $text = $quotes->render($quotes->authorName('Ada', 'Lovelace', 'ada'), $notes);

        $this->assertSame(
            "Ada Lovelace wrote:\n> Check the gate.\n> [...]\n> Then ship.",
            $text,
        );
    }

    public function test_quote_normalizes_line_endings_and_falls_back_to_login(): void
    {
        $quotes = new JournalQuoteText;

        $this->assertSame(
            "ada wrote:\n> first\n> second",
            $quotes->render($quotes->authorName('', null, 'ada'), "first\r\nsecond"),
        );
        $this->assertSame(
            "User wrote:\n> hi",
            $quotes->render($quotes->authorName('  ', null, ''), 'hi'),
        );
    }
}
