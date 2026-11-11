<?php

namespace tests\unit;

use app\components\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** RFC 6238 appendix B (SHA-1), last six digits */
    public static function rfcVectors(): array
    {
        return [[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037'], [20000000000, '353130']];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $code): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame($code, Totp::code($secret, Totp::counterAt($time)));
    }

    public function testVerifyAcceptsOneStepEitherSideAndNoReplay(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret), '160 bits');
        $now = 1_800_000_000;
        $step = Totp::counterAt($now);
        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $step), $now));
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $now), 'a slow clock');
        $this->assertSame($step + 1, Totp::verify($secret, ' ' . substr(Totp::code($secret, $step + 1), 0, 3) . ' ' . substr(Totp::code($secret, $step + 1), 3), $now), 'spaces are ignored');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), $now), 'too old');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step), $now, $step), 'already used');
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 1), $now, $step), 'older than the last used');
        $this->assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $now, $step));
        $this->assertNull(Totp::verify($secret, '12345', $now));
        $this->assertNull(Totp::verify($secret, 'abcdef', $now));
    }

    public function testUriAndFormatting(): void
    {
        $uri = Totp::uri('ABCDEFGH', 'j.doe', 'Acme Security');
        $this->assertSame('otpauth://totp/Acme%20Security:j.doe?secret=ABCDEFGH&issuer=Acme%20Security&algorithm=SHA1&digits=6&period=30', $uri);
        $this->assertSame('ABCD EFGH IJ', Totp::formatSecret('ABCDEFGHIJ'));
    }

    public function testBase32RoundTripAndErrors(): void
    {
        foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', random_bytes(20)] as $bytes) {
            $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'), 'RFC 4648 (unpadded)');
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb-oi=='), 'case, spaces, dashes and padding are ignored');
        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('ABC1');
    }
}
