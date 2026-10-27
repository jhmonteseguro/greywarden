<?php

namespace app\modules\intel\components;

use app\modules\intel\components\sources\Source;
use Yii;

/**
 * Runs every applicable source for an indicator and returns a Report.
 *
 * Phase 1 sources run first (their HTTP requests in parallel), then phase 2
 * sources with the facts gathered so far (origin ASN, BGP prefix).
 * Answers are cached per source (Source::ttl(); failures for 5 minutes);
 * $refresh = true bypasses the cache.
 *
 * Special-purpose indicators (private / documentation ranges, private ASNs)
 * are never sent to external services; only local sources run.
 */
final class Investigator
{
    public const CACHE_VERSION = 2;
    public const ERROR_TTL = 300;

    public static function run(Indicator $indicator, bool $refresh = false): Report
    {
        $started = microtime(true);
        $sources = array_filter(SourceRegistry::all(), static fn(Source $s) => $s->supports($indicator));
        $special = $indicator->specialPurpose();
        $results = [];
        $facts = [];

        foreach ([1, 2] as $phase) {
            $pending = [];
            $requests = [];
            foreach ($sources as $key => $source) {
                if ($source->phase($indicator) !== $phase) {
                    continue;
                }
                if ($special !== null && !$source->isLocal()) {
                    $results[$key] = SourceResult::skipped('Not queried: ' . $special);
                    continue;
                }
                if (!$source->isEnabled()) {
                    $results[$key] = SourceResult::skipped('Disabled');
                    continue;
                }
                if (!$source->isConfigured()) {
                    $results[$key] = SourceResult::unconfigured('Free API key needed: set ' . $source->apiKeyEnv() . ' in .env');
                    continue;
                }
                $cacheKey = self::cacheKey($source, $indicator, $facts);
                if (!$refresh && $source->ttl() > 0 && ($hit = Yii::$app->cache->get($cacheKey)) !== false) {
                    $results[$key] = SourceResult::fromArray($hit);
                    $results[$key]->cached = true;
                    continue;
                }
                $pending[$key] = $cacheKey;
                foreach ($source->requests($indicator, $facts) as $name => $request) {
                    $requests["$key|$name"] = $request;
                }
            }

            $responses = Http::fetchAll($requests);
            foreach ($pending as $key => $cacheKey) {
                $source = $sources[$key];
                $own = [];
                $httpTime = 0.0;
                foreach ($responses as $id => $response) {
                    [$owner, $name] = explode('|', $id, 2);
                    if ($owner === $key) {
                        $own[$name] = $response;
                        $httpTime = max($httpTime, $response['time']);
                    }
                }
                $t = microtime(true);
                try {
                    $result = $source->collect($indicator, $own, $facts);
                } catch (\Throwable $e) {
                    Yii::info(sprintf('Intel source %s failed for %s: %s', $key, $indicator, $e->getMessage()), 'intel');
                    $result = SourceResult::error('Source error: ' . mb_substr($e->getMessage(), 0, 160));
                }
                $result->time = round($httpTime + microtime(true) - $t, 3);
                $result->fetchedAt = time();
                if ($source->ttl() > 0) {
                    Yii::$app->cache->set($cacheKey, $result->toArray(), $result->status === SourceResult::ERROR ? self::ERROR_TTL : $source->ttl());
                }
                $results[$key] = $result;
            }

            // Merge facts in registry order: the first source to report a fact wins
            foreach ($sources as $key => $source) {
                if (isset($results[$key]) && $source->phase($indicator) === $phase) {
                    $facts += $results[$key]->facts;
                }
            }
        }

        // Keep registry order for display
        $ordered = [];
        foreach (array_keys($sources) as $key) {
            if (isset($results[$key])) {
                $ordered[$key] = $results[$key];
            }
        }
        return new Report($indicator, $ordered, $facts, round(microtime(true) - $started, 3));
    }

    private static function cacheKey(Source $source, Indicator $indicator, array $facts): array
    {
        // Phase 2 answers depend on the facts they were given (e.g. the origin ASN)
        $inputs = $source->phase($indicator) === 2 ? [$facts['asn'] ?? null] : [];
        return ['intel', self::CACHE_VERSION, $source->key(), $indicator->type, $indicator->value, $inputs];
    }
}
