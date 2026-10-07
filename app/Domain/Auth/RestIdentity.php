<?php

namespace App\Domain\Auth;

use App\Models\User;

/**
 * Result of REST authentication. `412` is a failed user switch.
 */
final class RestIdentity
{
    public function __construct(
        public readonly ?User $user,
        public readonly int $status,
    ) {}
}
