<?php

namespace Tests\Parity\Support;

use LDAP\Connection;
use RuntimeException;

/**
 * Loads the live LDAP fixture and allows anonymous reads of people entries.
 */
final class LiveLdapSeed
{
    public static function apply(): void
    {
        self::allowAnonymousRead();
        $conn = self::connect(self::env('LDAP_ADMIN_DN'), self::env('LDAP_ADMIN_PASSWORD'));
        try {
            foreach (self::entries(self::ldifPath()) as $entry) {
                $dn = $entry['dn'];
                unset($entry['dn']);
                if (@ldap_add($conn, $dn, $entry)) {
                    continue;
                }
                if (ldap_errno($conn) === 68) {
                    continue;
                }
                throw new RuntimeException(ldap_error($conn).' for '.$dn);
            }
        } finally {
            @ldap_unbind($conn);
        }
    }

    public static function ldifPath(): string
    {
        return dirname(__DIR__, 3).'/tests/Parity/fixtures/redmine-7.0.1/ldap/directory.ldif';
    }

    private static function allowAnonymousRead(): void
    {
        $conn = self::connect(self::env('LDAP_CONFIG_DN'), self::env('LDAP_CONFIG_PASSWORD'));
        try {
            $suffix = self::env('LDAP_BASE_DN');
            $found = @ldap_search($conn, 'cn=config', '(olcSuffix='.$suffix.')', ['dn']);
            if ($found === false) {
                throw new RuntimeException(ldap_error($conn));
            }
            $rows = ldap_get_entries($conn, $found);
            $dn = is_array($rows) ? ($rows[0]['dn'] ?? null) : null;
            if (! is_string($dn) || $dn === '') {
                throw new RuntimeException('LDAP database configuration was not found.');
            }
            $admin = self::env('LDAP_ADMIN_DN');
            $rules = [
                'to attrs=userPassword,shadowLastChange by self write by dn="'.$admin.'" write by anonymous auth by * none',
                'to * by self read by dn="'.$admin.'" write by anonymous read by users read by * none',
            ];
            if (! @ldap_mod_replace($conn, $dn, ['olcAccess' => $rules])) {
                throw new RuntimeException(ldap_error($conn));
            }
        } finally {
            @ldap_unbind($conn);
        }
    }

    private static function connect(string $dn, string $password): Connection
    {
        $conn = ldap_connect('ldap://'.self::env('LDAP_HOST').':'.self::env('LDAP_PORT'));
        if (! $conn instanceof Connection) {
            throw new RuntimeException('LDAP connection failed.');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if (! @ldap_bind($conn, $dn, $password)) {
            $error = ldap_error($conn);
            @ldap_unbind($conn);
            throw new RuntimeException($error);
        }

        return $conn;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(string $path): array
    {
        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw new RuntimeException('LDAP fixture is missing.');
        }
        $entries = [];
        $current = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                if ($current !== []) {
                    $entries[] = $current;
                    $current = [];
                }

                continue;
            }
            $split = explode(': ', $line, 2);
            if (count($split) !== 2) {
                continue;
            }
            [$name, $value] = $split;
            if ($name === 'dn') {
                $current['dn'] = $value;

                continue;
            }
            if (! isset($current[$name])) {
                $current[$name] = $value;

                continue;
            }
            if (is_string($current[$name])) {
                $current[$name] = [$current[$name], $value];

                continue;
            }
            if (is_array($current[$name])) {
                $current[$name][] = $value;
            }
        }
        if ($current !== []) {
            $entries[] = $current;
        }

        return $entries;
    }

    private static function env(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            $fromEnv = $_ENV[$name] ?? null;
            $fromServer = $_SERVER[$name] ?? null;
            $value = is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : $fromServer;
        }
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Missing '.$name.'.');
        }

        return $value;
    }
}
