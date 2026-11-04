<?php

namespace app\modules\threat\components;

use app\modules\intel\components\IpAddress;
use InvalidArgumentException;

/**
 * Keeps custom feeds on the public internet. Greywarden fetches these addresses from inside
 * the Docker network, next to the database, so an address that leads there (directly, by
 * DNS, or by redirect) must not be fetched.
 *
 *   - https on the normal port only, with no credentials in the address
 *   - every address the name resolves to must be public
 *   - the fetch is pinned to the checked address, so DNS cannot change between the check and
 *     the connection, and redirects are not followed (the error names where it pointed)
 */
final class FeedUrlGuard
{
    public const PORT = 443;

    /** @var callable|null fn(string $host): string[]  test double */
    public static $resolver = null;

    /**
     * @return array{host: string, ips: string[]}
     * @throws InvalidArgumentException with a message for the person who entered it
     */
    public static function check(string $url): array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || FeedParser::safeUrl(trim($url)) === null || empty($parts['host'])) {
            throw new InvalidArgumentException('Enter a full feed address, starting with https://.');
        }
        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidArgumentException('The feed address must use https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('The feed address must not contain a username or password.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== self::PORT) {
            throw new InvalidArgumentException('The feed address must use the standard https port.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $ips = IpAddress::isValid($host) ? [$host] : self::resolve($host);
        if (!$ips) {
            throw new InvalidArgumentException(sprintf('%s could not be found.', $host));
        }
        foreach ($ips as $ip) {
            if (!IpAddress::isPublic($ip)) {
                throw new InvalidArgumentException('The feed address leads to a private or reserved network.');
            }
        }
        return ['host' => $host, 'ips' => $ips];
    }

    /**
     * An Http request for a custom feed.
     * @throws InvalidArgumentException when the address is not allowed
     */
    public static function request(string $url, int $timeout): array
    {
        $target = self::check($url);
        $ip = $target['ips'][0];
        $resolve = sprintf('%s:%d:%s', $target['host'], self::PORT, IpAddress::version($ip) === 6 ? "[$ip]" : $ip);
        return ['url' => trim($url), 'timeout' => $timeout, 'follow' => false, 'resolve' => [$resolve]];
    }

    /** @return string[] */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        return array_values(array_filter(array_map(static fn(array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
