<?php

namespace Tests\Unit;

use App\Domain\Auth\RedminePassword;
use Tests\TestCase;

class RedminePasswordTest extends TestCase
{
    public function test_digest_is_sha1_of_salt_plus_inner_sha1(): void
    {
        $passwords = new RedminePassword;
        $salt = '00112233445566778899aabbccddeeff';

        $this->assertSame('922e9e1d8810f5e7bcc3f593a8f74f8af73a36f0', $passwords->hash('secret', $salt));
        $this->assertTrue($passwords->verify('secret', $salt, '922e9e1d8810f5e7bcc3f593a8f74f8af73a36f0'));
        $this->assertTrue($passwords->verify('secret', $salt, '922E9E1D8810F5E7BCC3F593A8F74F8AF73A36F0'));
        $this->assertFalse($passwords->verify('other', $salt, '922e9e1d8810f5e7bcc3f593a8f74f8af73a36f0'));
    }

    public function test_blank_salt_uses_an_empty_prefix(): void
    {
        $passwords = new RedminePassword;

        $this->assertSame('8e2ad3b8e7ee8cdf34d66b120fae70625ab1a4ae', $passwords->hash('secret', ''));
        $this->assertTrue($passwords->verify('secret', null, '8e2ad3b8e7ee8cdf34d66b120fae70625ab1a4ae'));
        $this->assertTrue($passwords->verify('secret', '', '8e2ad3b8e7ee8cdf34d66b120fae70625ab1a4ae'));
    }

    public function test_placeholder_and_empty_password_never_verify(): void
    {
        $passwords = new RedminePassword;
        $sealed = $passwords->seal('secret');

        $this->assertFalse($passwords->verify('secret', 'anything', RedminePassword::PLACEHOLDER_HASH));
        $this->assertFalse($passwords->verify('secret', $sealed['salt'], ''));
        $this->assertFalse($passwords->verify('secret', $sealed['salt'], 'not-a-digest'));
        $this->assertFalse($passwords->verify('', $sealed['salt'], $sealed['hashed_password']));
        $this->assertTrue($passwords->verify('secret', $sealed['salt'], $sealed['hashed_password']));
    }

    public function test_seal_writes_a_32_character_salt_and_a_40_character_digest(): void
    {
        $passwords = new RedminePassword;
        $sealed = $passwords->seal('secret');

        $this->assertSame(32, strlen($sealed['salt']));
        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $sealed['salt']));
        $this->assertSame(40, strlen($sealed['hashed_password']));
        $this->assertNotSame($sealed['salt'], $passwords->generateSalt());
    }
}
