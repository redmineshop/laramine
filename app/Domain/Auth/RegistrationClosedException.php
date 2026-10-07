<?php

namespace App\Domain\Auth;

use App\Domain\DomainException;

/**
 * Self-registration is turned off (`self_registration` = 0).
 */
final class RegistrationClosedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Self-registration is disabled.');
    }
}
