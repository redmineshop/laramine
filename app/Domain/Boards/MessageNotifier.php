<?php

namespace App\Domain\Boards;

use App\Domain\Notifications\MailIdentity;
use App\Domain\Notifications\ModuleRecipientResolver;
use App\Domain\Notifications\NotifiedEventCatalog;
use App\Domain\Notifications\NotifiedEventSetting;
use App\Domain\Notifications\OutboundMail;
use App\Domain\TextFormatting\FormattedText;
use App\Domain\Watchers\WatcherLedger;
use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;

/**
 * Queues `message_posted` for a new topic or reply.
 *
 * Editing a message does not send this event. The text part is the stored
 * content. The HTML part is the formatted content.
 */
final class MessageNotifier
{
    public function __construct(
        private readonly NotifiedEventSetting $events,
        private readonly ModuleRecipientResolver $recipients,
        private readonly MailIdentity $identity,
        private readonly OutboundMail $mail,
        private readonly WatcherLedger $watchers,
        private readonly FormattedText $formatted,
    ) {}

    public function posted(User $actor, Project $project, Board $board, Message $message, Message $topic): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::MESSAGE_POSTED)) {
            return;
        }
        $createdOn = $message->getAttribute('created_on');
        $topicCreated = $topic->getAttribute('created_on');
        if (! $createdOn instanceof DateTimeInterface || ! $topicCreated instanceof DateTimeInterface) {
            return;
        }

        $owners = [];
        if (is_numeric($message->author_id)) {
            $owners[] = (int) $message->author_id;
        }
        if (is_numeric($topic->author_id)) {
            $owners[] = (int) $topic->author_id;
        }
        $headers = $this->identity->commonHeaders((string) $actor->login);
        $headers['X-Redmine-Project'] = (string) $project->identifier;
        $headers['X-Redmine-Topic-Id'] = (string) $topic->id;
        $subject = '['.(string) $project->name.' - '.(string) $board->name.'] '.$message->subject;
        $stored = is_string($message->content) ? $message->content : '';
        $body = $subject."\n"
            .'Project: '.$project->identifier."\n"
            .'Board: '.$board->name."\n"
            .'Message: '.$message->id."\n"
            .'Topic: '.$topic->id."\n"
            .'Text:'."\n"
            .$stored."\n";
        $rendered = $this->formatted->message($message, $project, true);
        $html = $rendered === '' ? null : '<div class="wiki">'.$rendered.'</div>';
        $messageId = $this->identity->messageId('message', (int) $message->id, $createdOn);
        $references = [$this->identity->messageId('message', (int) $topic->id, $topicCreated)];

        foreach ($this->recipients->recipients(
            $actor,
            $project,
            'view_messages',
            array_values(array_unique($owners)),
            $this->watchers->userIds(WatcherLedger::MESSAGE, (int) $topic->id),
        ) as $user) {
            foreach ($this->recipients->addresses($user) as $address) {
                $this->mail->queue($address, $subject, $body, $messageId, $references, $headers, $html);
            }
        }
    }
}
