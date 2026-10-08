<?php

namespace App\Domain\Auth;

/**
 * Permission names carried by the OAuth bearer token for this request.
 *
 * A null list is API-key or password authentication and does not filter.
 * A blank scope string is unrestricted. A non-blank list is intersected
 * with the actor's roles. The `admin` name is the account-administration scope.
 */
final class OauthScope
{
    /**
     * @var list<string>|null
     */
    private ?array $names = null;

    public function clear(): void
    {
        $this->names = null;
    }

    public function restrict(string $scopes): void
    {
        $names = [];
        $parts = preg_split('/\s+/', trim($scopes));
        if ($parts === false) {
            $this->names = [];

            return;
        }
        foreach ($parts as $part) {
            if ($part !== '') {
                $names[] = $part;
            }
        }
        $this->names = $names;
    }

    public function filtering(): bool
    {
        return $this->names !== null && $this->names !== [];
    }

    public function permits(string $permission): bool
    {
        if (! $this->filtering()) {
            return true;
        }

        return in_array($permission, $this->names ?? [], true);
    }
}
