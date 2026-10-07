<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One queued notification. The text part is the summary. bodyHtml is the
 * formatted alternative when the event has wiki text. Headers carry the
 * Redmine thread and project markers.
 */
class RedmineNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $references
     * @param  array<string, string>  $headerLines
     */
    public function __construct(
        public readonly string $recipient,
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly string $messageId,
        public readonly array $references,
        public readonly array $headerLines,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly ?string $bodyHtml = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            to: [new Address($this->recipient)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        if ($this->bodyHtml === null) {
            return new Content(
                text: 'mail.notification',
            );
        }

        return new Content(
            html: 'mail.notification-html',
            text: 'mail.notification',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: $this->headerLines,
        );
    }
}
