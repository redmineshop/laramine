<?php

namespace Tests\Unit;

use App\Domain\Issues\History\JournalActionList;
use App\Domain\Issues\History\JournalDetailFormatter;
use App\Domain\Issues\History\JournalPropertyLine;
use App\Domain\Issues\History\TextileEmphasis;
use PHPUnit\Framework\TestCase;

class JournalPresentationTest extends TestCase
{
    public function test_textile_emphasis_renders_italics_and_escapes_html(): void
    {
        $html = (new TextileEmphasis)->render('Shipped with *emphasis* in the note. <b>');

        $this->assertSame('Shipped with <em>emphasis</em> in the note. &lt;b&gt;', $html);
        $this->assertSame('**', (new TextileEmphasis)->render('**'));
        $this->assertSame('a <em>b</em> and <em>c</em>', (new TextileEmphasis)->render('a *b* and *c*'));
    }

    public function test_attribute_lines_italicize_old_and_new_values(): void
    {
        $formatter = new JournalDetailFormatter;
        $status = $formatter->attribute('Status', 'New', 'In Progress');
        $done = $formatter->attribute('% Done', '0', '30');

        $this->assertInstanceOf(JournalPropertyLine::class, $status);
        $this->assertSame('Status changed from New to In Progress', $status->text);
        $this->assertSame('Status changed from <em>New</em> to <em>In Progress</em>', $status->html);
        $this->assertInstanceOf(JournalPropertyLine::class, $done);
        $this->assertSame('% Done changed from 0 to 30', $done->text);
        $this->assertSame('% Done changed from <em>0</em> to <em>30</em>', $done->html);
        $this->assertSame('Subject set to Later', $formatter->attribute('Subject', null, 'Later')?->text);
        $this->assertSame('Subject deleted (Later)', $formatter->attribute('Subject', 'Later', null)?->text);
        $this->assertNull($formatter->attribute('Subject', 'Same', 'Same'));
    }

    public function test_relation_add_line_names_the_other_issue(): void
    {
        $line = JournalPropertyLine::relationAdded('Related to', 'Bug', 2, 'Sample issue 2');

        $this->assertSame('Related to Bug #2: Sample issue 2 added', $line->text);
        $this->assertSame($line->text, $line->html);
        $this->assertStringNotContainsString('<em>', $line->html);
    }

    public function test_note_journals_show_quote_and_edit_property_only_journals_do_not(): void
    {
        $actions = new JournalActionList;
        $withNote = $actions->forJournal(true);
        $propertyOnly = $actions->forJournal(false);

        $this->assertSame(['reaction', 'quote', 'edit', 'more'], array_map(
            static fn ($action) => $action->key,
            $withNote,
        ));
        $this->assertSame('thumbs-up', $withNote[0]->label);
        $this->assertSame('quote', $withNote[1]->label);
        $this->assertSame('edit', $withNote[2]->label);
        $this->assertSame('pencil', $withNote[2]->icon);
        $this->assertSame('⋯', $withNote[3]->label);
        $this->assertSame(['reaction', 'more'], array_map(
            static fn ($action) => $action->key,
            $propertyOnly,
        ));
    }
}
