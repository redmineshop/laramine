<?php

namespace App\Domain\Notifications;

use App\Domain\Settings\SettingValue;
use DateTimeInterface;

/**
 * Host, From address, and deterministic message ids for outbound mail.
 *
 * The id is `redmine.{kind}-{id}.{stamp}@{host}` without angle brackets.
 * The host is the domain of `mail_from` when that value contains `@`,
 * otherwise `host_name`, otherwise `localhost`.
 */
final class MailIdentity
{
    public function __construct(
        private readonly SettingValue $settings,
    ) {}

    public function appTitle(): string
    {
        return $this->settings->appTitle();
    }

    public function host(): string
    {
        $from = $this->settings->mailFrom();
        $at = strrpos($from, '@');
        if ($at !== false) {
            $domain = strtolower(trim(substr($from, $at + 1), " \t<>"));
            if ($domain !== '') {
                return $domain;
            }
        }

        $host = $this->settings->hostName();

        return $host !== '' ? $host : 'localhost';
    }

    public function fromAddress(): string
    {
        $from = $this->settings->mailFrom();
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $match) === 1) {
            return $match[0];
        }

        return 'noreply@'.$this->host();
    }

    public function messageId(string $kind, int $id, DateTimeInterface $at): string
    {
        return 'redmine.'.$kind.'-'.$id.'.'.$at->format('YmdHis').'@'.$this->host();
    }

    /**
     * @return array<string, string>
     */
    public function commonHeaders(string $senderLogin): array
    {
        return [
            'X-Mailer' => 'Redmine',
            'X-Redmine-Host' => $this->settings->hostName() !== '' ? $this->settings->hostName() : $this->host(),
            'X-Redmine-Site' => $this->appTitle(),
            'X-Redmine-Sender' => $senderLogin,
            'X-Auto-Response-Suppress' => 'All',
            'Auto-Submitted' => 'auto-generated',
        ];
    }
}
