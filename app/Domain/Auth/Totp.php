<?php

namespace App\Domain\Auth;

use InvalidArgumentException;

/**
 * RFC 6238 TOTP with HMAC-SHA1, a 30-second period, and 6 digits.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public function generateSecret(): string
    {
        return $this->encodeBase32(random_bytes(20));
    }

    /**
     * Unix time of the accepted step, or null when the code is rejected.
     *
     * `$lastUsedAt` is the unix time of the last accepted step. A step at or
     * before that time is a replay.
     */
    public function verify(string $base32Secret, string $code, int $now, ?int $lastUsedAt): ?int
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return null;
        }

        $key = $this->decodeBase32($base32Secret);
        if ($key === null) {
            return null;
        }

        $timestep = intdiv($now, self::PERIOD);
        foreach ([$timestep - 1, $timestep, $timestep + 1] as $candidate) {
            if ($candidate < 0) {
                continue;
            }
            $usedAt = $candidate * self::PERIOD;
            if ($lastUsedAt !== null && $usedAt <= $lastUsedAt) {
                continue;
            }
            if (hash_equals($this->hotp($key, $candidate, self::DIGITS), $code)) {
                return $usedAt;
            }
        }

        return null;
    }

    public function codeFor(string $base32Secret, int $now): string
    {
        $key = $this->decodeBase32($base32Secret);
        if ($key === null) {
            throw new InvalidArgumentException('TOTP secret is not base32.');
        }

        return $this->hotp($key, intdiv($now, self::PERIOD), self::DIGITS);
    }

    /**
     * HMAC-SHA1 dynamic truncation. Exposed so the RFC 6238 vector can be checked.
     */
    public function hotp(string $key, int $counter, int $digits): string
    {
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $hmac = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($hmac[19]) & 0x0F;
        $unpacked = unpack('N', substr($hmac, $offset, 4));
        $binary = (is_array($unpacked) ? (int) $unpacked[1] : 0) & 0x7FFFFFFF;
        $modulus = 1;
        for ($index = 0; $index < $digits; $index++) {
            $modulus *= 10;
        }

        return str_pad((string) ($binary % $modulus), $digits, '0', STR_PAD_LEFT);
    }

    private function encodeBase32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $length = strlen($bytes);
        for ($index = 0; $index < $length; $index++) {
            $bits .= str_pad(decbin(ord($bytes[$index])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = (int) ceil(strlen($bits) / 5);
        for ($index = 0; $index < $chunks; $index++) {
            $chunk = substr($bits, $index * 5, 5);
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $position = $this->bitsToInt($chunk);
            if ($position > 31) {
                continue;
            }
            $encoded .= $alphabet[$position];
        }

        return $encoded;
    }

    private function decodeBase32(string $secret): ?string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
        if ($secret === '' || strlen($secret) < 16) {
            return null;
        }

        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $bits = '';
        $length = strlen($secret);
        for ($index = 0; $index < $length; $index++) {
            $character = $secret[$index];
            if (! isset($alphabet[$character])) {
                return null;
            }
            $bits .= str_pad(decbin($alphabet[$character]), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        $byteCount = intdiv(strlen($bits), 8);
        for ($index = 0; $index < $byteCount; $index++) {
            $bytes .= chr($this->bitsToInt(substr($bits, $index * 8, 8)));
        }

        return $bytes === '' ? null : $bytes;
    }

    private function bitsToInt(string $bits): int
    {
        $value = 0;
        $length = strlen($bits);
        for ($index = 0; $index < $length; $index++) {
            $value = ($value << 1) | ($bits[$index] === '1' ? 1 : 0);
        }

        return $value;
    }
}
