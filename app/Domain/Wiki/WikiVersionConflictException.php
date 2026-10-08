<?php

namespace App\Domain\Wiki;

use App\Domain\DomainException;

/**
 * The submitted wiki version or section hash is no longer current.
 */
final class WikiVersionConflictException extends DomainException
{
    public const MESSAGE = 'Wiki page was updated by another user.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
