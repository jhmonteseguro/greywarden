<?php

namespace app\modules\intel\components;

/**
 * The intelligence sources, in display / fact-priority order: when two sources
 * report the same fact (country, ASN …) the earlier one wins.
 * Add a source by extending sources\Source and listing it here.
 */
final class SourceRegistry
{
    /** @var sources\Source[]|null */
    private static ?array $sources = null;

    /** @return array<string, sources\Source> key => source */
    public static function all(): array
    {
        if (self::$sources === null) {
            $list = [
                new sources\FeedSource(),
                new sources\CymruSource(),
                new sources\RipeStatSource(),
                new sources\ReverseDnsSource(),
                new sources\IpInfoSource(),
                new sources\IpApiSource(),
                new sources\RdapSource(),
                new sources\WhoisSource(),
                new sources\PeeringDbSource(),
                new sources\GreyNoiseSource(),
                new sources\InternetDbSource(),
                new sources\OtxSource(),
                new sources\AbuseIpDbSource(),
                new sources\VirusTotalSource(),
                new sources\AbuseChSource(),
                new sources\DnsblSource(),
            ];
            self::$sources = [];
            foreach ($list as $source) {
                self::$sources[$source->key()] = $source;
            }
        }
        return self::$sources;
    }

    public static function get(string $key): ?sources\Source
    {
        return self::all()[$key] ?? null;
    }

    /** Replace the source list (tests) */
    public static function set(?array $sources): void
    {
        self::$sources = $sources === null ? null : array_combine(array_map(static fn($s) => $s->key(), $sources), $sources);
    }

    public static function categoryLabels(): array
    {
        return [
            sources\Source::CAT_FEEDS => 'Threat feeds',
            sources\Source::CAT_REPUTATION => 'Reputation',
            sources\Source::CAT_DNSBL => 'DNS blocklists',
            sources\Source::CAT_EXPOSURE => 'Exposure',
            sources\Source::CAT_NETWORK => 'Network & routing',
            sources\Source::CAT_REGISTRY => 'Registration',
        ];
    }
}
