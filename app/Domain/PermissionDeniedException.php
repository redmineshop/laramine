<?php

namespace App\Domain;

/**
 * Raised when a write is rejected because a permission name is not effective.
 */
class PermissionDeniedException extends DomainException
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct('Permission denied: '.$permission);
    }
}
