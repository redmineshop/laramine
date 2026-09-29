<?php

namespace App\Domain\Acl;

/**
 * One Redmine permission name and the flags that change authorization.
 */
final class PermissionDefinition
{
    /**
     * @param  'loggedin'|'member'|null  $require
     */
    public function __construct(
        public readonly string $name,
        public readonly string $module,
        public readonly bool $public,
        public readonly bool $read,
        public readonly ?string $require,
    ) {}

    public function isModular(): bool
    {
        return $this->module !== 'project';
    }
}
