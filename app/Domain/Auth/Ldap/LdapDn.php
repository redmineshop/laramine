<?php

namespace App\Domain\Auth\Ldap;

/**
 * DN value escaping for the `$login` account template.
 *
 * The first `$login` token is replaced. Characters that a DN value must quote
 * are prefixed with a backslash. Later `$login` tokens are left as written.
 */
final class LdapDn
{
    public static function escape(string $value): string
    {
        $escaped = preg_replace_callback(
            '/^ |^#| $|[,+"\\\\<>;]/',
            static fn (array $match): string => '\\'.$match[0],
            $value,
        );

        return is_string($escaped) ? $escaped : $value;
    }

    public static function substituteLogin(string $account, string $login): string
    {
        $needle = '$login';
        $position = strpos($account, $needle);
        if ($position === false) {
            return $account;
        }

        return substr($account, 0, $position)
            .self::escape($login)
            .substr($account, $position + strlen($needle));
    }
}
