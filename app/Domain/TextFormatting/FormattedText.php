<?php

namespace App\Domain\TextFormatting;

use App\Models\Document;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiContent;
use App\Models\WikiPage;

/**
 * Formatted HTML for the records Redmine 7.0.1 renders.
 */
final class FormattedText
{
    public function __construct(
        private readonly TextFormatter $formatter,
    ) {}

    public function html(string $text, FormattingContext $context, ?string $format = null): string
    {
        return $this->formatter->toHtml($text, $context, $format);
    }

    public function wrapped(string $text, FormattingContext $context, ?string $format = null): string
    {
        $html = $this->html($text, $context, $format);
        if ($html === '') {
            return '';
        }

        return '<div class="wiki">'.$html.'</div>';
    }

    public function issueDescription(Issue $issue, ?User $viewer = null, bool $absolute = false): string
    {
        $project = $issue->project;

        return $this->html((string) $issue->description, new FormattingContext(
            $project instanceof Project ? $project : null,
            $issue,
            $absolute,
            false,
            $viewer,
        ));
    }

    public function journalNote(Journal $journal, ?Project $project, ?User $viewer = null, bool $absolute = false): string
    {
        $notes = is_string($journal->notes) ? $journal->notes : '';

        return $this->html($notes, new FormattingContext($project, $journal, $absolute, false, $viewer));
    }

    public function wiki(WikiContent $content, Project $project, ?User $viewer = null, bool $sectionEdit = false, bool $absolute = false): string
    {
        $text = is_string($content->text) ? $content->text : '';

        return $this->html($text, new FormattingContext($project, $content, $absolute, $sectionEdit, $viewer));
    }

    public function news(News $news, bool $absolute = false, ?User $viewer = null): string
    {
        $project = $news->project;

        return $this->html((string) $news->description, new FormattingContext(
            $project instanceof Project ? $project : null,
            $news,
            $absolute,
            false,
            $viewer,
        ));
    }

    public function message(Message $message, ?Project $project, bool $absolute = false, ?User $viewer = null): string
    {
        $text = is_string($message->content) ? $message->content : '';

        return $this->html($text, new FormattingContext($project, $message, $absolute, false, $viewer));
    }

    public function document(Document $document, bool $absolute = false, ?User $viewer = null): string
    {
        $project = $document->project;

        return $this->html((string) $document->description, new FormattingContext(
            $project instanceof Project ? $project : null,
            $document,
            $absolute,
            false,
            $viewer,
        ));
    }

    public function pageSection(WikiPage $page, Project $project, ?User $viewer, bool $sectionEdit): FormattingContext
    {
        return new FormattingContext($project, $page, false, $sectionEdit, $viewer);
    }
}
