<?php

namespace App\Domain\Auth\Oauth;

use App\Domain\DomainException;

/**
 * OAuth token or authorize error. `$error` is the protocol error code.
 */
final class OauthException extends DomainException
{
    public function __construct(public readonly string $error, string $description = '')
    {
        parent::__construct($description !== '' ? $description : $error);
    }
}
