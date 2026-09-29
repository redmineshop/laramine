<?php

namespace App\Domain\Acl;

/**
 * Values stored in `roles.builtin`.
 *
 * Custom roles are the only ones assigned through `members`.
 */
final class BuiltinRole
{
    public const CUSTOM = 0;

    public const NON_MEMBER = 1;

    public const ANONYMOUS = 2;
}
