<?php

namespace App\Domain\Auth\Ldap;

use RuntimeException;

/**
 * The directory rejected the connection or the service bind.
 */
final class LdapBindException extends RuntimeException {}
