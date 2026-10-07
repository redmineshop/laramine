<?php

namespace App\Domain\Wiki;

use App\Domain\Notifications\MailIdentity;
use App\Domain\Notifications\ModuleRecipientResolver;
use App\Domain\Notifications\NotifiedEventCatalog;
use App\Domain\Notifications\NotifiedEventSetting;
use App\Domain\Notifications\OutboundMail;
use App\Domain\Watchers\WatcherLedger;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiContent;
use App\Models\WikiPage;
use DateTimeInterface;

/**
 * Queues `wiki_content_added` and `wiki_content_updated`.
 *
 * The body is the stored text. Textile and Markdown are not rendered.
 */
final class WikiNotifier
{
    public function __construct(
        private readonly NotifiedEventSetting $events,
        private readonly ModuleRecipientResolver $recipients,
        private readonly MailIdentity $identity,
        private readonly OutboundMail $mail,
        private readonly WatcherLedger $watchers,
    ) {}

    public function saved(User $actor, Project $project, WikiPage $page, WikiContent $content, bool $added): void
    {
        $event = $added ? NotifiedEventCatalog::WIKI_CONTENT_ADDED : NotifiedEventCatalog::WIKI_CONTENT_UPDATED;
        if (! $this->events->allows($event)) {
            return;
        }
        $updatedOn = $content->getAttribute('updated_on');
        $createdOn = $page->getAttribute('created_on');
        if (! $updatedOn instanceof DateTimeInterface || ! $createdOn instanceof DateTimeInterface) {
            return;
        }

        $authorId = is_numeric($content->author_id) ? (int) $content->author_id : 0;
        $headers = $this->identity->commonHeaders((string) $actor->login);
        $headers['X-Redmine-Project'] = (string) $project->identifier;
        $headers['X-Redmine-Wiki-Page-Id'] = (string) $page->id;
        $headers['X-Redmine-Wiki-Page-Title'] = (string) $page->title;
        $subject = '['.(string) $project->name.' - Wiki] '.$page->title;
        $body = $subject."\n"
            .'Project: '.$project->identifier."\n"
            .'Wiki page: '.$page->title."\n"
            .'Version: '.$content->version."\n"
            .'Text:'."\n"
            .(is_string($content->text) ? $content->text : '')."\n";
        $messageId = $this->identity->messageId('wiki_content', (int) $content->id, $updatedOn);
        $references = [$this->identity->messageId('wiki_page', (int) $page->id, $createdOn)];

        foreach ($this->recipients->recipients(
            $actor,
            $project,
            'view_wiki_pages',
            $authorId > 0 ? [$authorId] : [],
            $this->watchers->userIds(WatcherLedger::WIKI_PAGE, (int) $page->id),
        ) as $user) {
            foreach ($this->recipients->addresses($user) as $address) {
                $this->mail->queue($address, $subject, $body, $messageId, $references, $headers);
            }
        }
    }
}
