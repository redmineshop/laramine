<?php

namespace App\Domain\Auth\Ldap;

/**
 * One directory entry returned by an LDAP adapter.
 */
final class LdapEntry
{
    /**
     * @param  array<string, list<string>>  $attributes
     */
    public function __construct(
        public readonly string $dn,
        public readonly array $attributes,
    ) {}

    public function first(string $name): string
    {
        foreach ($this->attributes as $key => $values) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }
            foreach ($values as $value) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }
}
