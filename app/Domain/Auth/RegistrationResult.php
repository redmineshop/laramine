<?php

namespace App\Domain\Auth;

use App\Models\Token;
use App\Models\User;

/**
 * Outcome of one self-registration. The token is set only for email activation.
 */
final readonly class RegistrationResult
{
    public function __construct(
        public User $user,
        public SelfRegistrationMode $mode,
        public ?Token $token,
    ) {}
}
