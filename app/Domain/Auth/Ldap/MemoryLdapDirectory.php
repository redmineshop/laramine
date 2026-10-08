<?php

namespace App\Domain\Auth\Ldap;

use App\Models\AuthSource;

/**
 * In-memory directory. Entries are matched by AND-ed equality clauses in the filter.
 */
final class MemoryLdapDirectory implements LdapDirectory
{
    /**
     * @var array<int, list<array{dn: string, password: string, attributes: array<string, list<string>>}>>
     */
    private array $entries = [];

    /**
     * @var array<int, array{account: string, password: string}>
     */
    private array $services = [];

    /**
     * @param  array<string, list<string>|string>  $attributes
     */
    public function add(int $sourceId, string $dn, string $password, array $attributes): void
    {
        $normalized = [];
        foreach ($attributes as $name => $value) {
            $normalized[$name] = is_string($value) ? [$value] : $value;
        }
        $this->entries[$sourceId][] = [
            'dn' => $dn,
            'password' => $password,
            'attributes' => $normalized,
        ];
    }

    public function setServiceAccount(int $sourceId, string $account, string $password): void
    {
        $this->services[$sourceId] = [
            'account' => $account,
            'password' => $password,
        ];
    }

    public function search(AuthSource $source, string $filter, ?string $login = null, ?string $password = null, ?int $sizeLimit = null): array
    {
        $this->assertService($source);
        $clauses = $this->clauses($filter);
        if ($clauses === []) {
            return [];
        }

        $matched = [];
        foreach ($this->entries[(int) $source->id] ?? [] as $entry) {
            if ($this->matches($entry['attributes'], $clauses)) {
                $matched[] = new LdapEntry($entry['dn'], $entry['attributes']);
            }
        }

        return $matched;
    }

    public function authenticate(AuthSource $source, string $dn, string $password): bool
    {
        $this->assertService($source);
        if ($password === '') {
            return false;
        }

        foreach ($this->entries[(int) $source->id] ?? [] as $entry) {
            if (strcasecmp($entry['dn'], $dn) !== 0) {
                continue;
            }

            return $this->same($entry['password'], $password);
        }

        return false;
    }

    public function testConnection(AuthSource $source): void
    {
        if (trim((string) $source->host) === '') {
            throw new LdapBindException('LDAP host is blank.');
        }
        $account = (string) $source->account;
        if (trim($account) !== '' && ! str_contains($account, '$login') && (string) $source->account_password !== '') {
            $this->assertService($source);
        }
    }

    private function assertService(AuthSource $source): void
    {
        $account = trim((string) $source->account);
        if ($account === '') {
            return;
        }

        $known = $this->services[(int) $source->id] ?? null;
        $password = (string) $source->account_password;
        if ($known === null || $known['account'] !== $account || ! $this->same($known['password'], $password)) {
            throw new LdapBindException('Service account bind failed.');
        }
    }

    /**
     * @param  array<string, list<string>>  $attributes
     * @param  list<array{0: string, 1: string}>  $clauses
     */
    private function matches(array $attributes, array $clauses): bool
    {
        foreach ($clauses as [$name, $expected]) {
            $found = false;
            foreach ($attributes as $key => $values) {
                if (strcasecmp($key, $name) !== 0) {
                    continue;
                }
                foreach ($values as $value) {
                    if (strcasecmp($value, $expected) === 0) {
                        $found = true;
                    }
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function clauses(string $filter): array
    {
        $matches = [];
        $count = preg_match_all('/\(([a-zA-Z][a-zA-Z0-9]*)=([^()*]*)\)/', $filter, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return [];
        }

        $clauses = [];
        foreach ($matches as $match) {
            $clauses[] = [$match[1], $this->unescape($match[2])];
        }

        return $clauses;
    }

    private function unescape(string $value): string
    {
        $replaced = preg_replace_callback('/\\\\([0-9a-fA-F]{2})/', function (array $match): string {
            return chr(intval($match[1], 16));
        }, $value);

        return is_string($replaced) ? $replaced : $value;
    }

    private function same(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($left, $right);
    }
}
