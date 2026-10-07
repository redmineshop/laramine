<?php

namespace App\Domain\Notifications;

use App\Domain\TextFormatting\FormattedText;
use App\Domain\TextFormatting\FormattingContext;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Document;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues news, document, and file notifications after the row is stored.
 *
 * The module must be enabled, including for an administrator. Recipients are
 * the project event list from `IssueRecipientResolver`. A news comment also
 * includes watchers of that news. A document file uses `document_added`.
 * A project or version file uses `file_added`. Message and wiki events stay unsent.
 */
final class ModuleNotifier
{
    public function __construct(
        private readonly NotifiedEventSetting $events,
        private readonly IssueRecipientResolver $recipients,
        private readonly MailIdentity $identity,
        private readonly OutboundMail $mail,
        private readonly FormattedText $formatted,
    ) {}

    public function newsAdded(User $actor, News $news): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::NEWS_ADDED)) {
            return;
        }
        $project = $this->project($news->project);
        $createdOn = $this->moment($news);
        if (! $project instanceof Project || ! $project->isModuleEnabled('news') || ! $createdOn instanceof DateTimeInterface) {
            return;
        }

        $messageId = $this->identity->messageId('news', (int) $news->id, $createdOn);
        $this->deliver(
            $actor,
            $project,
            'view_news',
            null,
            null,
            '['.$project->name.'] News: '.$news->title,
            "News: {$news->title}\nProject: {$project->identifier}\n",
            $this->wiki((string) $news->description, $project, $news),
            $messageId,
            [$messageId],
        );
    }

    public function newsCommentAdded(User $actor, News $news, Comment $comment): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::NEWS_COMMENT_ADDED)) {
            return;
        }
        $project = $this->project($news->project);
        $createdOn = $this->moment($comment);
        $newsCreated = $this->moment($news);
        if (! $project instanceof Project || ! $project->isModuleEnabled('news') || ! $createdOn instanceof DateTimeInterface || ! $newsCreated instanceof DateTimeInterface) {
            return;
        }

        $this->deliver(
            $actor,
            $project,
            'view_news',
            'News',
            (int) $news->id,
            'Re: ['.$project->name.'] News: '.$news->title,
            "News: {$news->title}\nProject: {$project->identifier}\nComment: {$comment->content}\n",
            $this->wiki((string) $comment->content, $project, $news),
            $this->identity->messageId('comment', (int) $comment->id, $createdOn),
            [$this->identity->messageId('news', (int) $news->id, $newsCreated)],
        );
    }

    public function documentAdded(User $actor, Document $document): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::DOCUMENT_ADDED)) {
            return;
        }
        $project = $this->project($document->project);
        $createdOn = $this->moment($document);
        if (! $project instanceof Project || ! $project->isModuleEnabled('documents') || ! $createdOn instanceof DateTimeInterface) {
            return;
        }

        $messageId = $this->identity->messageId('document', (int) $document->id, $createdOn);
        $this->deliver(
            $actor,
            $project,
            'view_documents',
            null,
            null,
            '['.$project->name.'] New document: '.$document->title,
            "Document: {$document->title}\nProject: {$project->identifier}\n",
            $this->wiki((string) $document->description, $project, $document),
            $messageId,
            [$messageId],
        );
    }

    public function documentFileAdded(User $actor, Document $document, Attachment $attachment): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::DOCUMENT_ADDED)) {
            return;
        }
        $project = $this->project($document->project);
        $createdOn = $this->moment($attachment);
        $documentCreated = $this->moment($document);
        if (! $project instanceof Project || ! $project->isModuleEnabled('documents') || ! $createdOn instanceof DateTimeInterface || ! $documentCreated instanceof DateTimeInterface) {
            return;
        }

        $this->deliver(
            $actor,
            $project,
            'view_documents',
            null,
            null,
            '['.$project->name.'] New file',
            "File: {$attachment->filename}\nProject: {$project->identifier}\nDocument: {$document->title}\n",
            null,
            $this->identity->messageId('attachment', (int) $attachment->id, $createdOn),
            [$this->identity->messageId('document', (int) $document->id, $documentCreated)],
        );
    }

    public function fileAdded(User $actor, Project $project, Attachment $attachment): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::FILE_ADDED)) {
            return;
        }
        $project = $this->project($project);
        $createdOn = $this->moment($attachment);
        if (! $project instanceof Project || ! $project->isModuleEnabled('files') || ! $createdOn instanceof DateTimeInterface) {
            return;
        }

        $messageId = $this->identity->messageId('attachment', (int) $attachment->id, $createdOn);
        $this->deliver(
            $actor,
            $project,
            'view_files',
            null,
            null,
            '['.$project->name.'] New file',
            "File: {$attachment->filename}\nProject: {$project->identifier}\n",
            null,
            $messageId,
            [$messageId],
        );
    }

    /**
     * @param  list<string>  $references
     */
    private function deliver(
        User $actor,
        Project $project,
        string $viewPermission,
        ?string $watchableType,
        ?int $watchableId,
        string $subject,
        string $body,
        ?string $html,
        string $messageId,
        array $references,
    ): void {
        $headers = $this->identity->commonHeaders((string) $actor->login);
        $headers['X-Redmine-Project'] = (string) $project->identifier;
        foreach ($this->recipients->projectEventRecipients($actor, $project, $viewPermission, $watchableType, $watchableId) as $user) {
            foreach ($this->recipients->addresses($user) as $address) {
                $this->mail->queue($address, $subject, $body, $messageId, $references, $headers, $html);
            }
        }
    }

    private function wiki(string $text, Project $project, Model $object): ?string
    {
        $html = $this->formatted->html($text, new FormattingContext($project, $object, true));

        return $html === '' ? null : '<div class="wiki">'.$html.'</div>';
    }

    private function project(mixed $project): ?Project
    {
        return $project instanceof Project ? $project : null;
    }

    private function moment(Model $model): ?DateTimeInterface
    {
        $value = $model->getAttribute('created_on');
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        return null;
    }
}
