<?php

namespace App\Domain\Auth;

use App\Domain\DomainException;

/**
 * A recovery or register token cannot be used.
 */
final class TokenRejectedException extends DomainException
{
    public function __construct(string $message = 'Token is not usable.')
    {
        parent::__construct($message);
    }
}
