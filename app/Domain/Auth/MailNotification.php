<?php

namespace App\Domain\Auth;

/**
 * `users.mail_notification` values. Delivery is outside this slice.
 */
final class MailNotification
{
    /**
     * @var list<string>
     */
    public const VALUES = [
        'all',
        'selected',
        'only_my_events',
        'only_assigned',
        'only_owner',
        'none',
    ];

    public static function valid(string $value): bool
    {
        return in_array($value, self::VALUES, true);
    }
}
