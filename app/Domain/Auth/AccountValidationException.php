<?php

namespace App\Domain\Auth;

use App\Domain\DomainException;

/**
 * Field errors for registration, recovery, or a password change.
 */
final class AccountValidationException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Account validation failed.');
    }
}
