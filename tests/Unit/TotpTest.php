<?php

namespace Tests\Unit;

use App\Domain\Auth\Totp;
use Tests\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_sha1_vector_and_replay_window(): void
    {
        $totp = new Totp;

        $this->assertSame('94287082', $totp->hotp('12345678901234567890', 1, 8));

        $secret = $totp->generateSecret();
        $now = 1_700_000_030;
        $code = $totp->codeFor($secret, $now);
        $used = $totp->verify($secret, $code, $now, null);
        $this->assertSame(intdiv($now, Totp::PERIOD) * Totp::PERIOD, $used);
        $this->assertNull($totp->verify($secret, $code, $now, $used));
        $this->assertNotNull($totp->verify($secret, $totp->codeFor($secret, $now + Totp::PERIOD), $now + Totp::PERIOD, $used));
        $this->assertNull($totp->verify($secret, '000000', $now, null));
    }
}
