<?php

namespace App\Domain\Auth\Ldap;

/**
 * The directory did not answer before `auth_sources.timeout`.
 */
final class LdapTimeoutException extends LdapBindException {}
