<?php

namespace App\Domain\Notifications;

use App\Mail\RedmineNotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * Queues one plain-text notification per address.
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
        ));
    }
}
