<?php

namespace App\Domain\Attachments;

use App\Domain\DomainException;
use App\Domain\Settings\SettingValue;

/**
 * Attachment size and extension limits.
 *
 * `attachment_max_size` is kilobytes. A missing value is 5120. An empty
 * allow-list accepts every extension that is not denied. A non-empty
 * allow-list is a whitelist, and the deny-list still rejects a match.
 */
final class AttachmentRules
{
    public function __construct(private readonly SettingValue $settings) {}

    public function assertAccepted(string $filename, int $bytes): void
    {
        if ($bytes < 1) {
            throw new DomainException('Attachment file is empty.');
        }
        $limit = $this->settings->attachmentMaxKilobytes() * 1024;
        if ($bytes > $limit) {
            throw new DomainException('Attachment file is too large.');
        }

        $extension = $this->extension($filename);
        $allowed = $this->settings->attachmentExtensionsAllowed();
        if ($allowed !== [] && ! in_array($extension, $allowed, true)) {
            throw new DomainException('Attachment extension is not allowed.');
        }
        $denied = $this->settings->attachmentExtensionsDenied();
        if ($extension !== '' && in_array($extension, $denied, true)) {
            throw new DomainException('Attachment extension is not allowed.');
        }
    }

    private function extension(string $filename): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === strlen($filename) - 1) {
            return '';
        }

        return strtolower(substr($filename, $dot + 1));
    }
}
