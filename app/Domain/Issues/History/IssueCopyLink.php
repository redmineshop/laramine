<?php

namespace App\Domain\Issues\History;

use App\Domain\Settings\SettingValue;

/**
 * Absolute issue URL copied from a journal.
 *
 * The path is `/issues/{id}#note-{n}`. This slice does not register that HTTP
 * route. `host_name` is a host and optional port. A blank or unusable host
 * uses `localhost:3000`. `protocol` is `http` or `https`.
 */
final class IssueCopyLink
{
    public function __construct(private readonly SettingValue $settings) {}

    public function forNote(int $issueId, int $displayNumber): string
    {
        return self::compose(
            $this->settings->protocol(),
            $this->settings->hostName(),
            $issueId,
            $displayNumber,
        );
    }

    public static function compose(string $protocol, string $hostName, int $issueId, int $displayNumber): string
    {
        $scheme = strtolower(trim($protocol)) === 'https' ? 'https' : 'http';
        $host = trim($hostName);
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || preg_match('/\s/', $host) === 1) {
            $host = 'localhost:3000';
        }

        return $scheme.'://'.$host.'/issues/'.$issueId.'#note-'.$displayNumber;
    }
}
