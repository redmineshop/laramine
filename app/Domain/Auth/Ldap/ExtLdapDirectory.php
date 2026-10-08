<?php

namespace App\Domain\Auth\Ldap;

use App\Models\AuthSource;
use LDAP\Connection;

/**
 * Live directory through PHP's LDAP extension.
 *
 * `tls` opens LDAPS. STARTTLS is not sent. A blank account binds anonymously.
 * An account containing `$login` binds as that DN with the user's password.
 * Any other account binds with `account_password`.
 */
final class ExtLdapDirectory implements LdapDirectory
{
    private const INVALID_CREDENTIALS = 49;

    private const SIZE_LIMIT = 4;

    public function search(AuthSource $source, string $filter, ?string $login = null, ?string $password = null, ?int $sizeLimit = null): array
    {
        $started = microtime(true);
        $timeout = $this->timeout($source);
        $conn = $this->connect($source);
        try {
            $this->bindForSearch($conn, $source, $login, $password, $timeout, $started);
            $limit = $sizeLimit === null ? 0 : $sizeLimit;
            $result = @ldap_search(
                $conn,
                $this->base($source),
                $filter,
                ['*'],
                0,
                $limit,
                $timeout,
            );
            if ($result === false && ldap_errno($conn) !== self::SIZE_LIMIT) {
                $this->raise($conn, $timeout, $started);
            }
            if ($result === false) {
                return [];
            }
            $raw = ldap_get_entries($conn, $result);
            if (! is_array($raw)) {
                return [];
            }

            return $this->entries($raw);
        } finally {
            $this->close($conn);
        }
    }

    public function authenticate(AuthSource $source, string $dn, string $password): bool
    {
        if ($dn === '' || $password === '') {
            return false;
        }
        $started = microtime(true);
        $timeout = $this->timeout($source);
        $conn = $this->connect($source);
        try {
            $ok = @ldap_bind($conn, $dn, $password);
            if ($ok) {
                return true;
            }
            if (ldap_errno($conn) === self::INVALID_CREDENTIALS) {
                return false;
            }
            $this->raise($conn, $timeout, $started);
        } finally {
            $this->close($conn);
        }
    }

    public function testConnection(AuthSource $source): void
    {
        $started = microtime(true);
        $timeout = $this->timeout($source);
        $this->openSocket($source, $timeout, $started);
        $account = (string) $source->account;
        $secret = (string) $source->account_password;
        if (trim($account) === '' || str_contains($account, '$login') || $secret === '') {
            return;
        }
        $conn = $this->connect($source);
        try {
            if (! @ldap_bind($conn, $account, $secret)) {
                if (ldap_errno($conn) === self::INVALID_CREDENTIALS) {
                    throw new LdapBindException('Service account bind failed.');
                }
                $this->raise($conn, $timeout, $started);
            }
        } finally {
            $this->close($conn);
        }
    }

    private function bindForSearch(Connection $conn, AuthSource $source, ?string $login, ?string $password, int $timeout, float $started): void
    {
        $account = (string) $source->account;
        if (str_contains($account, '$login')) {
            if ($login === null || $password === null || $password === '') {
                throw new LdapBindException('Service account bind failed.');
            }
            $dn = LdapDn::substituteLogin($account, $login);
            if (! @ldap_bind($conn, $dn, $password)) {
                $this->raise($conn, $timeout, $started);
            }

            return;
        }
        $secret = (string) $source->account_password;
        if (trim($account) === '' && $secret === '') {
            if (! @ldap_bind($conn)) {
                $this->raise($conn, $timeout, $started);
            }

            return;
        }
        if (! @ldap_bind($conn, $account, $secret)) {
            if (ldap_errno($conn) === self::INVALID_CREDENTIALS) {
                throw new LdapBindException('Service account bind failed.');
            }
            $this->raise($conn, $timeout, $started);
        }
    }

    private function connect(AuthSource $source): Connection
    {
        if (! function_exists('ldap_connect')) {
            throw new LdapBindException('LDAP connection failed.');
        }
        $host = $this->host($source);
        $port = $this->port($source);
        $timeout = $this->timeout($source);
        if ($source->tls) {
            $require = $source->verify_peer ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER;
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $require);
        }
        $scheme = $source->tls ? 'ldaps' : 'ldap';
        $conn = ldap_connect(sprintf('%s://%s:%d', $scheme, $host, $port));
        if (! $conn instanceof Connection) {
            throw new LdapBindException('LDAP connection failed.');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($conn, LDAP_OPT_TIMEOUT, $timeout);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, $timeout);

        return $conn;
    }

    private function openSocket(AuthSource $source, int $timeout, float $started): void
    {
        $host = $this->host($source);
        $port = $this->port($source);
        $scheme = $source->tls ? 'ssl' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => (bool) $source->verify_peer,
                'verify_peer_name' => (bool) $source->verify_peer,
                'allow_self_signed' => ! $source->verify_peer,
                'SNI_enabled' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $host, $port),
            $errno,
            $errstr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($client === false) {
            if ($this->streamTimedOut($errno, $errstr, $started, $timeout)) {
                throw new LdapTimeoutException('LDAP timed out.');
            }
            throw new LdapBindException('LDAP connection failed.');
        }
        fclose($client);
    }

    private function host(AuthSource $source): string
    {
        $host = trim((string) $source->host);
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || str_contains($host, ' ')) {
            throw new LdapBindException('LDAP host is blank.');
        }

        return $host;
    }

    private function port(AuthSource $source): int
    {
        $stored = $source->port;
        if ($stored === null || $stored === '' || (is_numeric($stored) && (int) $stored === 0)) {
            return 389;
        }
        if (! is_numeric($stored)) {
            throw new LdapBindException('LDAP connection failed.');
        }
        $port = (int) $stored;
        if ($port < 1 || $port > 65535) {
            throw new LdapBindException('LDAP connection failed.');
        }

        return $port;
    }

    private function base(AuthSource $source): string
    {
        return trim((string) $source->base_dn);
    }

    private function timeout(AuthSource $source): int
    {
        return LdapTimeout::seconds($source->timeout);
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<LdapEntry>
     */
    private function entries(array $raw): array
    {
        $count = $raw['count'] ?? 0;
        if (! is_int($count)) {
            return [];
        }
        $matched = [];
        for ($index = 0; $index < $count; $index++) {
            $row = $raw[$index] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $dn = $row['dn'] ?? '';
            if (! is_string($dn) || $dn === '') {
                continue;
            }
            $matched[] = new LdapEntry($dn, $this->attributes($row));
        }

        return $matched;
    }

    /**
     * @param  array<mixed>  $row
     * @return array<string, list<string>>
     */
    private function attributes(array $row): array
    {
        $count = $row['count'] ?? 0;
        if (! is_int($count)) {
            return [];
        }
        $attributes = [];
        for ($index = 0; $index < $count; $index++) {
            $name = $row[$index] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $values = $row[$name] ?? null;
            if (! is_array($values)) {
                continue;
            }
            $valueCount = $values['count'] ?? 0;
            if (! is_int($valueCount)) {
                continue;
            }
            $list = [];
            for ($valueIndex = 0; $valueIndex < $valueCount; $valueIndex++) {
                $value = $values[$valueIndex] ?? null;
                if (is_string($value)) {
                    $list[] = $value;
                }
            }
            $attributes[$name] = $list;
        }

        return $attributes;
    }

    private function raise(Connection $conn, int $timeout, float $started): never
    {
        $errno = ldap_errno($conn);
        $message = ldap_error($conn);
        $elapsed = microtime(true) - $started;
        $timed = $errno === -5
            || $errno === 85
            || (is_string($message) && str_contains(strtolower($message), 'timed out'))
            || $elapsed >= ($timeout - 0.05);
        if ($timed) {
            throw new LdapTimeoutException('LDAP timed out.');
        }
        if ($errno === self::INVALID_CREDENTIALS) {
            throw new LdapBindException('Service account bind failed.');
        }
        throw new LdapBindException('LDAP connection failed.');
    }

    private function streamTimedOut(int $errno, string $errstr, float $started, int $timeout): bool
    {
        $elapsed = microtime(true) - $started;
        if ($errno === 110 || str_contains(strtolower($errstr), 'timed out')) {
            return true;
        }

        return $elapsed >= ($timeout - 0.05);
    }

    private function close(Connection $conn): void
    {
        @ldap_unbind($conn);
    }
}
