<?php

namespace App\Domain\Auth\Ldap;

use RuntimeException;

/**
 * The directory rejected the connection or the service bind.
 *
 * Not final: a timeout is the same failure for sign-in, and
 * LdapTimeoutException extends this class so those catches still apply.
 */
class LdapBindException extends RuntimeException {}
