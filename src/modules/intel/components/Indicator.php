<?php

namespace app\modules\intel\components;

/**
 * An investigated indicator: a single IP address or an autonomous system.
 * Construct with Indicator::parse(); the value is always normalised.
 */
final class Indicator
{
    public const TYPE_IP = 'ip';
    public const TYPE_ASN = 'asn';

    /** ASN ranges that are never on the public internet: [from, to, label] */
    public const SPECIAL_ASNS = [
        [0, 0, 'Reserved (RFC 7607)'],
        [23456, 23456, 'AS_TRANS (RFC 6793)'],
        [64496, 64511, 'Documentation (RFC 5398)'],
        [64512, 65534, 'Private use (RFC 6996)'],
        [65535, 65535, 'Reserved (RFC 7300)'],
        [65536, 65551, 'Documentation (RFC 5398)'],
        [65552, 131071, 'Reserved by IANA'],
        [4200000000, 4294967294, 'Private use (RFC 6996)'],
        [4294967295, 4294967295, 'Reserved (RFC 7300)'],
    ];

    private function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly ?int $asn = null,
    ) {
    }

    /** Accepts "192.0.2.1", "[2001:db8::1]", "AS64496", "as 64496" or a bare number (ASN). */
    public static function parse(string $input): ?self
    {
        $input = trim($input);
        if ($ip = IpAddress::normalize($input)) {
            return new self(self::TYPE_IP, $ip);
        }
        if (preg_match('/^(?:AS\s*)?(\d{1,10})$/i', $input, $m) && (int) $m[1] <= 4294967295) {
            return self::asn((int) $m[1]);
        }
        return null;
    }

    public static function ip(string $ip): ?self
    {
        $ip = IpAddress::normalize($ip);
        return $ip ? new self(self::TYPE_IP, $ip) : null;
    }

    public static function asn(int $asn): self
    {
        return new self(self::TYPE_ASN, 'AS' . $asn, $asn);
    }

    public function isIp(): bool
    {
        return $this->type === self::TYPE_IP;
    }

    public function isAsn(): bool
    {
        return $this->type === self::TYPE_ASN;
    }

    public function ipVersion(): ?int
    {
        return $this->isIp() ? IpAddress::version($this->value) : null;
    }

    /** Why this indicator is not on the public internet (private range, documentation ASN ...), or null. */
    public function specialPurpose(): ?string
    {
        if ($this->isIp()) {
            return IpAddress::specialPurpose($this->value);
        }
        foreach (self::SPECIAL_ASNS as [$from, $to, $label]) {
            if ($this->asn >= $from && $this->asn <= $to) {
                return $label;
            }
        }
        return null;
    }

    public function isPublic(): bool
    {
        return $this->specialPurpose() === null;
    }

    /** Route of the investigation page */
    public function url(array $params = []): array
    {
        return $this->isIp()
            ? array_merge(['/intel/investigate/ip', 'ip' => $this->value], $params)
            : array_merge(['/intel/investigate/asn', 'asn' => $this->value], $params);
    }

    public function typeLabel(): string
    {
        return $this->isIp() ? 'IPv' . $this->ipVersion() . ' address' : 'Autonomous system';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
