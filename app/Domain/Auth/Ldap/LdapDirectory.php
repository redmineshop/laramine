<?php

namespace App\Domain\Auth\Ldap;

use App\Models\AuthSource;

/**
 * Directory port used by LDAP sign-in.
 *
 * The in-memory adapter is the tested implementation. A live server is an
 * external service and is not contacted by the parity comparison.
 */
interface LdapDirectory
{
    /**
     * @return list<LdapEntry>
     */
    public function search(AuthSource $source, string $filter): array;

    public function authenticate(AuthSource $source, string $dn, string $password): bool;
}
