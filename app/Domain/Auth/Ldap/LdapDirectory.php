<?php

namespace App\Domain\Auth\Ldap;

use App\Models\AuthSource;

/**
 * Directory port used by LDAP sign-in.
 *
 * `MemoryLdapDirectory` is the default binding while the application is under
 * test. `ExtLdapDirectory` talks to a live server.
 */
interface LdapDirectory
{
    /**
     * @return list<LdapEntry>
     */
    public function search(AuthSource $source, string $filter, ?string $login = null, ?string $password = null, ?int $sizeLimit = null): array;

    public function authenticate(AuthSource $source, string $dn, string $password): bool;

    /**
     * Open the server. A fixed account with a password must bind.
     * An account that contains `$login`, or a blank account, only opens the socket.
     */
    public function testConnection(AuthSource $source): void;
}
