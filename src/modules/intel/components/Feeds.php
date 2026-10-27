<?php

namespace app\modules\intel\components;

use app\components\Actor;
use Yii;
use yii\db\Query;
use yii\mutex\FileMutex;

/**
 * Local copies of the bulk feeds in FeedCatalogue: download + parse + store
 * (intel_feed / intel_feed_entry) and matching.
 *
 * Within one feed, ranges are merged so they never overlap; a containment
 * lookup is then a single indexed "last range starting at or before the address".
 */
final class Feeds
{
    public const MUTEX = 'intel-feeds';
    /** Do not retry a failing feed more often than this (seconds) */
    public const RETRY_AFTER = 1800;

    // ---- Status -------------------------------------------------------------

    /** feed_key => intel_feed row */
    public static function status(): array
    {
        return (new Query())->from('intel_feed')->indexBy('feed_key')->all();
    }

    public static function idMap(): array
    {
        return array_map('intval', (new Query())->select(['id', 'feed_key'])->from('intel_feed')->indexBy('feed_key')->column());
    }

    public static function isLoaded(): bool
    {
        return (bool) (new Query())->from('intel_feed')->where(['>', 'entry_count', 0])->exists();
    }

    /** Keys whose local copy is older than maxAge (or missing), excluding recently failed ones */
    public static function staleKeys(bool $respectRetry = true): array
    {
        $status = self::status();
        $now = time();
        $stale = [];
        foreach (FeedCatalogue::all() as $key => $def) {
            $row = $status[$key] ?? null;
            $fetched = $row && $row['fetched_at'] ? strtotime($row['fetched_at']) : 0;
            $attempted = $row && $row['attempted_at'] ? strtotime($row['attempted_at']) : 0;
            if ($now - $fetched < $def['maxAge'] * 3600) {
                continue;
            }
            if ($respectRetry && $now - $attempted < self::RETRY_AFTER) {
                continue;
            }
            $stale[] = $key;
        }
        return $stale;
    }

    /**
     * Refresh stale feeds after the response has been sent to the browser
     * (PHP-FPM fastcgi_finish_request), so page loads never wait for downloads.
     */
    public static function refreshInBackground(): void
    {
        static $scheduled = false;
        if ($scheduled || YII_ENV_TEST || !function_exists('fastcgi_finish_request') || !self::staleKeys()) {
            return;
        }
        $scheduled = true;
        register_shutdown_function(static function () {
            fastcgi_finish_request();
            ignore_user_abort(true);
            set_time_limit(600);
            try {
                self::update();
            } catch (\Throwable $e) {
                Yii::info('Background feed refresh failed: ' . $e->getMessage(), 'intel');
            }
        });
    }

    // ---- Update -------------------------------------------------------------

    /**
     * Download and import feeds.
     * @param string[]|null $keys null = all stale feeds
     * @return array<string, array{ok: bool, count: int, error: ?string, ms: int}>
     */
    public static function update(?array $keys = null, bool $force = false): array
    {
        $mutex = new FileMutex();
        if (!$mutex->acquire(self::MUTEX)) {
            return [];
        }
        try {
            $catalogue = FeedCatalogue::all();
            $keys = $keys === null ? ($force ? array_keys($catalogue) : self::staleKeys(false)) : array_values(array_intersect($keys, array_keys($catalogue)));
            if (!$keys) {
                return [];
            }
            $requests = [];
            foreach ($keys as $key) {
                foreach ($catalogue[$key]['urls'] as $i => $url) {
                    $requests["$key#$i"] = ['url' => $url, 'timeout' => 60, 'headers' => ['Accept: */*']];
                }
            }
            $responses = Http::fetchAll($requests, 60);
            $out = [];
            foreach ($keys as $key) {
                $started = microtime(true);
                $bodies = [];
                $error = null;
                foreach (array_keys($catalogue[$key]['urls']) as $i) {
                    $r = $responses["$key#$i"] ?? null;
                    if (!$r || $r['status'] !== 200 || $r['body'] === '') {
                        $error = Http::errorText($r) . ' – ' . $catalogue[$key]['urls'][$i];
                        break;
                    }
                    $bodies[] = $r['body'];
                }
                $count = 0;
                if ($error === null) {
                    try {
                        $entries = self::parse($catalogue[$key], $bodies);
                        if (!$entries) {
                            throw new \RuntimeException('Feed parsed to 0 entries (format changed?)');
                        }
                        $count = self::store($key, $entries);
                    } catch (\Throwable $e) {
                        $error = mb_substr($e->getMessage(), 0, 240);
                    }
                }
                $download = 0.0;
                foreach (array_keys($catalogue[$key]['urls']) as $i) {
                    $download = max($download, $responses["$key#$i"]['time'] ?? 0.0);
                }
                $ms = (int) round(($download + microtime(true) - $started) * 1000);
                self::recordAttempt($key, $error, $ms);
                if ($error !== null) {
                    Yii::info("Feed $key failed: $error", 'intel');
                }
                $out[$key] = ['ok' => $error === null, 'count' => $count, 'error' => $error, 'ms' => $ms];
            }
            return $out;
        } finally {
            $mutex->release(self::MUTEX);
        }
    }

    private static function feedId(string $key): int
    {
        $db = Yii::$app->db;
        $id = (new Query())->select('id')->from('intel_feed')->where(['feed_key' => $key])->scalar();
        if (!$id) {
            $db->createCommand()->insert('intel_feed', ['feed_key' => $key])->execute();
            $id = $db->getLastInsertID();
        }
        return (int) $id;
    }

    private static function recordAttempt(string $key, ?string $error, int $ms): void
    {
        $cols = ['attempted_at' => Actor::now(), 'duration_ms' => $ms, 'last_error' => $error];
        if ($error === null) {
            $cols['fetched_at'] = Actor::now();
        }
        Yii::$app->db->createCommand()->update('intel_feed', $cols, ['id' => self::feedId($key)])->execute();
    }

    /**
     * Replace a feed's entries.
     * @param array $entries list of [startHex, endHex, meta] or ['asn' => int, 'meta' => ?string]
     */
    public static function store(string $key, array $entries): int
    {
        $db = Yii::$app->db;
        $feedId = self::feedId($key);
        $ranges = [];
        $asns = [];
        foreach ($entries as $e) {
            if (isset($e['asn'])) {
                $asns[(int) $e['asn']] = $e['meta'] ?? null;
            } else {
                $ranges[] = $e;
            }
        }
        $rows = [];
        foreach (IpAddress::mergeRanges($ranges) as [$start, $end, $meta]) {
            $rows[] = [$feedId, $start, $end, null, $meta !== null ? mb_substr($meta, 0, 255) : null];
        }
        foreach ($asns as $asn => $meta) {
            $rows[] = [$feedId, null, null, $asn, $meta !== null ? mb_substr($meta, 0, 255) : null];
        }
        $tx = $db->beginTransaction();
        try {
            $db->createCommand()->delete('intel_feed_entry', ['feed_id' => $feedId])->execute();
            foreach (array_chunk($rows, 2000) as $chunk) {
                $db->createCommand()->batchInsert('intel_feed_entry', ['feed_id', 'range_start', 'range_end', 'asn', 'meta'], $chunk)->execute();
            }
            $db->createCommand()->update('intel_feed', ['entry_count' => count($rows)], ['id' => $feedId])->execute();
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
        return count($rows);
    }

    // ---- Parsing ------------------------------------------------------------

    /** @return array list of [startHex, endHex, meta] | ['asn' => int, 'meta' => ?string] */
    public static function parse(array $def, array $bodies): array
    {
        $entries = [];
        foreach ($bodies as $body) {
            $entries = array_merge($entries, match ($def['format']) {
                'plain' => self::parsePlain($body),
                'ipsum' => self::parseIpsum($body),
                'feodo' => self::parseFeodo($body),
                'spamhaus-drop' => self::parseNdjson($body, static fn($r) => isset($r['cidr']) ? self::range($r['cidr'], $r['sblid'] ?? null) : null),
                'spamhaus-asndrop' => self::parseNdjson($body, static fn($r) => isset($r['asn'])
                    ? ['asn' => (int) $r['asn'], 'meta' => trim(($r['asname'] ?? '') . ' · ' . ($r['cc'] ?? '') . ' · ' . ($r['domain'] ?? ''), ' ·')] : null),
                'aws' => self::parseAws($body),
                'gcp' => self::parseGcp($body),
                'csv-cidr' => self::parseCsvCidr($body),
                default => throw new \InvalidArgumentException('Unknown feed format ' . $def['format']),
            });
        }
        if (!empty($def['skipSpecial'])) {
            $entries = array_values(array_filter($entries, static fn($e) => isset($e['asn']) || IpAddress::specialPurpose(IpAddress::fromHex($e[0])) === null));
        }
        return $entries;
    }

    /** IP or CIDR (/32 or /128 treated as a single address) => [start, end, meta] */
    public static function range(string $value, ?string $meta = null): ?array
    {
        $value = trim($value);
        if (str_contains($value, '/')) {
            $r = IpAddress::parseCidr($value);
            return $r ? [$r['start'], $r['end'], $meta] : null;
        }
        $ip = IpAddress::normalize($value);
        if ($ip === null) {
            return null;
        }
        $hex = IpAddress::toHex($ip);
        return [$hex, $hex, $meta];
    }

    public static function parsePlain(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/', $body) as $line) {
            $line = trim(preg_replace('/[#;].*$/', '', $line));
            if ($line === '') {
                continue;
            }
            $token = preg_split('/[\s,]+/', $line)[0];
            if ($r = self::range($token)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** "ip<TAB>count" */
    public static function parseIpsum(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/', $body) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 2 && ($r = self::range($p[0], (string) (int) $p[1]))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public static function parseFeodo(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Feodo Tracker: invalid JSON');
        }
        $out = [];
        foreach ($data as $row) {
            if (!isset($row['ip_address'])) {
                continue;
            }
            $meta = implode(' · ', array_filter([$row['malware'] ?? null, $row['status'] ?? null, isset($row['port']) ? 'port ' . $row['port'] : null,
                isset($row['last_online']) ? 'last online ' . $row['last_online'] : null]));
            if ($r = self::range($row['ip_address'], $meta)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** One JSON object per line (Spamhaus DROP v4/v6, ASN-DROP); metadata lines are skipped by the mapper */
    public static function parseNdjson(string $body, callable $map): array
    {
        $out = [];
        foreach (preg_split('/\R/', $body) as $line) {
            $row = json_decode(trim($line), true);
            if (is_array($row) && ($item = $map($row))) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public static function parseAws(string $body): array
    {
        $data = json_decode($body, true);
        $out = [];
        foreach (array_merge($data['prefixes'] ?? [], $data['ipv6_prefixes'] ?? []) as $p) {
            $cidr = $p['ip_prefix'] ?? $p['ipv6_prefix'] ?? null;
            // "AMAZON" duplicates the specific service entries
            $service = ($p['service'] ?? '') === 'AMAZON' ? null : ($p['service'] ?? null);
            if ($cidr && ($r = self::range($cidr, trim(($service ?? '') . ' ' . ($p['region'] ?? ''))))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public static function parseGcp(string $body): array
    {
        $data = json_decode($body, true);
        $out = [];
        foreach ($data['prefixes'] ?? [] as $p) {
            $cidr = $p['ipv4Prefix'] ?? $p['ipv6Prefix'] ?? null;
            if ($cidr && ($r = self::range($cidr, $p['scope'] ?? null))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** "cidr,country,region,city,postal" */
    public static function parseCsvCidr(string $body): array
    {
        $out = [];
        foreach (preg_split('/\R/', $body) as $line) {
            $p = str_getcsv($line, ',', '"', '');
            if (!empty($p[0]) && ($r = self::range($p[0], trim(implode(' ', array_filter([$p[3] ?? null, $p[1] ?? null]))) ?: null))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    // ---- Matching -----------------------------------------------------------

    /**
     * Feeds listing the address.
     * @return array<string, array{meta: ?string, range: string}> feed_key => match
     */
    public static function matchIp(string $ip): array
    {
        $hex = IpAddress::toHex($ip);
        $out = [];
        foreach (self::idMap() as $key => $feedId) {
            $row = (new Query())->select(['range_start', 'range_end', 'meta'])->from('intel_feed_entry')
                ->where(['feed_id' => $feedId])->andWhere(['<=', 'range_start', $hex])
                ->orderBy(['range_start' => SORT_DESC])->limit(1)->one();
            if ($row && strcmp($row['range_end'], $hex) >= 0) {
                $out[$key] = ['meta' => $row['meta'], 'range' => self::rangeLabel($row['range_start'], $row['range_end'])];
            }
        }
        return $out;
    }

    /** @return array<string, ?string> feed_key => meta */
    public static function matchAsn(int $asn): array
    {
        $rows = (new Query())->select(['f.feed_key', 'e.meta'])->from(['e' => 'intel_feed_entry'])
            ->innerJoin(['f' => 'intel_feed'], 'f.id = e.feed_id')
            ->where(['e.asn' => $asn])->all();
        return array_column($rows, 'meta', 'feed_key');
    }

    /**
     * Number of listed entries per threat feed inside the given ranges (prefixes of an AS, a /24 ...).
     * @param array<int, array{0: string, 1: string}> $ranges hex [start, end]
     * @return array<string, int> feed_key => count
     */
    public static function countInRanges(array $ranges, ?array $keys = null): array
    {
        $ids = array_intersect_key(self::idMap(), array_flip($keys ?? FeedCatalogue::threatKeys()));
        $ranges = IpAddress::mergeRanges($ranges);
        if (!$ids || !$ranges) {
            return [];
        }
        $byId = array_flip($ids);
        $counts = [];
        // Chunked OR of BETWEENs keeps each query on the (feed_id, range_start) index
        foreach (array_chunk($ranges, 200) as $chunk) {
            $or = ['or'];
            foreach ($chunk as [$start, $end]) {
                $or[] = ['between', 'range_start', $start, $end];
            }
            $rows = (new Query())->select(['feed_id', 'n' => 'COUNT(*)'])->from('intel_feed_entry')
                ->where(['feed_id' => array_values($ids)])->andWhere($or)->groupBy('feed_id')->all();
            foreach ($rows as $r) {
                $key = $byId[(int) $r['feed_id']];
                $counts[$key] = ($counts[$key] ?? 0) + (int) $r['n'];
            }
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Listed IPv4 entries per prefix (for the ASN prefix table), most listed first.
     * @param string[] $prefixes CIDRs
     * @return array<string, int> cidr => listed entries
     */
    public static function countPerPrefix(array $prefixes, int $limit = 400): array
    {
        $ids = array_values(array_intersect_key(self::idMap(), array_flip(FeedCatalogue::threatKeys())));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_slice($prefixes, 0, $limit) as $cidr) {
            $r = IpAddress::parseCidr($cidr);
            if (!$r) {
                continue;
            }
            $n = (int) (new Query())->from('intel_feed_entry')->where(['feed_id' => $ids])
                ->andWhere(['between', 'range_start', $r['start'], $r['end']])->count();
            if ($n > 0) {
                $out[$r['cidr']] = $n;
            }
        }
        arsort($out);
        return $out;
    }

    public static function rangeLabel(string $start, string $end): string
    {
        $a = IpAddress::fromHex($start);
        return $start === $end ? $a : $a . ' – ' . IpAddress::fromHex($end);
    }
}
