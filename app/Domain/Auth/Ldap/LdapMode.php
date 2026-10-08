<?php

namespace App\Domain\Auth\Ldap;

use App\Models\AuthSource;

/**
 * Connection mode stored by Redmine 7.0.1 on `auth_sources.tls` and `verify_peer`.
 *
 * The three names are the admin choices. `tls` selects LDAPS (TLS from the first
 * byte). STARTTLS is not a 7.0.1 column or mode.
 */
enum LdapMode: string
{
    case Plain = 'ldap';
    case LdapsVerifyNone = 'ldaps_verify_none';
    case LdapsVerifyPeer = 'ldaps_verify_peer';

    public static function fromSource(AuthSource $source): self
    {
        if ($source->tls && $source->verify_peer) {
            return self::LdapsVerifyPeer;
        }
        if ($source->tls) {
            return self::LdapsVerifyNone;
        }

        return self::Plain;
    }
}
