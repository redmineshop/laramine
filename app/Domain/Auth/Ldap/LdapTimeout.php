<?php

namespace App\Domain\Auth\Ldap;

/**
 * Seconds allowed for one directory operation.
 *
 * A blank, zero, or negative `auth_sources.timeout` uses 20 seconds.
 */
final class LdapTimeout
{
    public static function seconds(mixed $stored): int
    {
        if (! is_numeric($stored)) {
            return 20;
        }
        $seconds = (int) $stored;
        if ($seconds <= 0) {
            return 20;
        }

        return $seconds;
    }
}
