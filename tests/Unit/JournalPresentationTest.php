<?php

namespace Tests\Unit;

use App\Domain\Issues\History\JournalActionList;
use App\Domain\Issues\History\JournalDetailFormatter;
use App\Domain\Issues\History\JournalMenuItemView;
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

    public function test_updated_and_added_lines_keep_relation_style_plain(): void
    {
        $updated = JournalPropertyLine::updated('Description');
        $file = JournalPropertyLine::added('File', 'spec.pdf');
        $multi = JournalPropertyLine::added('Pin multi', 'alpha, beta', true);

        $this->assertSame('Description updated', $updated->text);
        $this->assertSame('Description updated', $updated->html);
        $this->assertSame('File spec.pdf added', $file->text);
        $this->assertSame('File spec.pdf added', $file->html);
        $this->assertSame('Pin multi alpha, beta added', $multi->text);
        $this->assertSame('Pin multi <em>alpha, beta</em> added', $multi->html);
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
        $withNote = $actions->forJournal(true, true, true, '#note-4');
        $propertyOnly = $actions->forJournal(false, true, true, '#note-5');

        $this->assertSame(['reaction', 'quote', 'edit', 'more'], array_map(
            static fn ($action) => $action->key,
            $withNote,
        ));
        $this->assertSame('thumbs-up', $withNote[0]->label);
        $this->assertSame('quote', $withNote[1]->label);
        $this->assertSame('edit', $withNote[2]->label);
        $this->assertSame('pencil', $withNote[2]->icon);
        $this->assertSame('⋯', $withNote[3]->label);
        $this->assertSame(['copy_link', 'delete'], array_map(
            static fn ($item) => $item->key,
            $withNote[3]->menuItems,
        ));
        $this->assertSame([JournalActionList::COPY_LINK, JournalActionList::DELETE], array_map(
            static fn ($item) => $item->label,
            $withNote[3]->menuItems,
        ));
        $this->assertSame('#note-4', $withNote[3]->menuItems[0]->fragment);
        $this->assertNull($withNote[3]->menuItems[1]->fragment);
        $this->assertSame(['reaction', 'more'], array_map(
            static fn ($action) => $action->key,
            $propertyOnly,
        ));
        $this->assertSame(['copy_link'], array_map(
            static fn ($item) => $item->key,
            $propertyOnly[1]->menuItems,
        ));
        $this->assertSame('#note-5', $propertyOnly[1]->menuItems[0]->fragment);
    }

    public function test_quote_and_edit_controls_follow_the_caller_flags(): void
    {
        $actions = new JournalActionList;
        $quoteOnly = $actions->forJournal(true, true, false, '#note-1');
        $editOnly = $actions->forJournal(true, false, true, '#note-1');
        $neither = $actions->forJournal(true, false, false, '#note-1');

        $this->assertSame(['reaction', 'quote', 'more'], array_map(
            static fn ($action) => $action->key,
            $quoteOnly,
        ));
        $this->assertSame(['copy_link'], array_map(
            static fn ($item) => $item->key,
            $quoteOnly[2]->menuItems,
        ));
        $this->assertSame(['reaction', 'edit', 'more'], array_map(
            static fn ($action) => $action->key,
            $editOnly,
        ));
        $this->assertSame(['copy_link', 'delete'], array_map(
            static fn ($item) => $item->key,
            $editOnly[2]->menuItems,
        ));
        $this->assertSame(['reaction', 'more'], array_map(
            static fn ($action) => $action->key,
            $neither,
        ));
        $this->assertSame(['copy_link'], array_map(
            static fn ($item) => $item->key,
            $neither[1]->menuItems,
        ));
    }

    public function test_download_all_files_is_listed_before_copy_link(): void
    {
        $download = new JournalMenuItemView(
            'download_all',
            JournalActionList::DOWNLOAD_ALL,
            null,
            'Journal',
            9,
        );
        $actions = (new JournalActionList)->forJournal(
            false,
            false,
            false,
            'https://tracker.example/issues/4#note-2',
            $download,
        );

        $this->assertSame(['reaction', 'more'], array_map(
            static fn ($action) => $action->key,
            $actions,
        ));
        $this->assertSame(['download_all', 'copy_link'], array_map(
            static fn ($item) => $item->key,
            $actions[1]->menuItems,
        ));
        $this->assertSame(JournalActionList::DOWNLOAD_ALL, $actions[1]->menuItems[0]->label);
        $this->assertNull($actions[1]->menuItems[0]->fragment);
        $this->assertSame('Journal', $actions[1]->menuItems[0]->containerType);
        $this->assertSame(9, $actions[1]->menuItems[0]->containerId);
        $this->assertSame('https://tracker.example/issues/4#note-2', $actions[1]->menuItems[1]->fragment);
    }
}
