<?php

namespace App\Domain\Auth\Ldap;

/**
 * One row returned by the "add user from LDAP" search.
 */
final class LdapUserMatch
{
    public function __construct(
        public readonly string $login,
        public readonly string $firstname,
        public readonly string $lastname,
        public readonly string $mail,
        public readonly string $dn,
    ) {}
}
