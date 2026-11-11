<?php

namespace app\components;

/**
 * Time-based one-time passwords (RFC 6238, the codes authenticator apps show): HMAC-SHA1,
 * 6 digits, 30-second steps, secrets in base32 (RFC 4648).
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    /** Steps either side of now that are accepted, for clocks that drift */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret: 160 random bits (the RFC 4226 recommendation) in base32 */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for a time step */
    public static function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function counterAt(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * The time step $code matches, or null. A code is accepted once: steps at or before
     * $lastCounter (the last one used) are refused, so a code seen over a shoulder cannot be replayed.
     */
    public static function verify(string $secret, string $code, int $time, ?int $lastCounter = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = self::counterAt($time);
        for ($counter = $now - self::WINDOW; $counter <= $now + self::WINDOW; $counter++) {
            if ($lastCounter !== null && $counter <= $lastCounter) {
                continue;
            }
            if (hash_equals(self::code($secret, $counter), $code)) {
                return $counter;
            }
        }
        return null;
    }

    /** The otpauth:// URI authenticator apps read from the QR code */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The secret in groups of four, for typing in by hand */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Not a base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
