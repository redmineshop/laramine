<?php

namespace App\Domain\Notifications;

use App\Mail\RedmineNotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * Queues one notification per address.
 *
 * The text part stays the stored summary. An HTML part is set when the
 * event has a formatted description, note, wiki page, news item, document,
 * or message.
 */
final class OutboundMail
{
    public function __construct(
        private readonly MailIdentity $identity,
    ) {}

    /**
     * @param  list<string>  $references
     * @param  array<string, string>  $headers
     */
    public function queue(
        string $address,
        string $subject,
        string $body,
        string $messageId,
        array $references,
        array $headers,
        ?string $html = null,
    ): void {
        Mail::queue(new RedmineNotificationMail(
            $address,
            $subject,
            $body,
            $messageId,
            $references,
            $headers,
            $this->identity->fromAddress(),
            $this->identity->appTitle(),
            $html === '' ? null : $html,
        ));
    }
}
