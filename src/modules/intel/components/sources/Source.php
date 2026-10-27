<?php

namespace app\modules\intel\components\sources;

use app\modules\intel\components\Indicator;
use app\modules\intel\components\SourceResult;

/**
 * One intelligence source.
 *
 * HTTP sources return their requests from requests(); the Investigator runs
 * the requests of all sources in parallel and hands each source its responses
 * in collect(). DNS / WHOIS / local sources do their own work in collect().
 *
 * Phase 2 sources run after phase 1 and receive the facts gathered so far
 * (e.g. the origin ASN of an IP, for PeeringDB and ASN-DROP).
 */
abstract class Source
{
    public const CAT_REGISTRY = 'registry';
    public const CAT_NETWORK = 'network';
    public const CAT_REPUTATION = 'reputation';
    public const CAT_EXPOSURE = 'exposure';
    public const CAT_DNSBL = 'dnsbl';
    public const CAT_FEEDS = 'feeds';

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function category(): string;

    /** One line about what the source provides */
    abstract public function description(): string;

    abstract public function homepage(): string;

    abstract public function supports(Indicator $indicator): bool;

    /** Cache lifetime of a successful answer, seconds */
    public function ttl(): int
    {
        return 6 * 3600;
    }

    /** 1, or 2 for sources that need facts from phase 1 (e.g. the origin ASN of an IP) */
    public function phase(Indicator $indicator): int
    {
        return 1;
    }

    /** Sources that only read local data are run for special-purpose (private, documentation ...) indicators too */
    public function isLocal(): bool
    {
        return false;
    }

    /** Does the answer count towards reputation coverage (assessment confidence)? */
    public function isReputation(): bool
    {
        return in_array($this->category(), [self::CAT_REPUTATION, self::CAT_DNSBL, self::CAT_FEEDS], true);
    }

    /** Free API key needed? Return the env variable name. */
    public function apiKeyEnv(): ?string
    {
        return null;
    }

    public function apiKey(): ?string
    {
        $env = $this->apiKeyEnv();
        $value = $env ? gw_env($env) : null;
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /** Usage terms worth showing on the sources page, e.g. "Free for non-commercial use" */
    public function terms(): ?string
    {
        return null;
    }

    /** Enabled unless switched off in .env (INTEL_DISABLE=greynoise,otx) or an opt-in source not enabled. */
    public function isEnabled(): bool
    {
        $disabled = gw_env_list('INTEL_DISABLE', []);
        return !in_array($this->key(), $disabled, true);
    }

    public function isConfigured(): bool
    {
        return $this->apiKeyEnv() === null || $this->apiKey() !== null;
    }

    /**
     * @return array<string, array> name => Http request
     */
    public function requests(Indicator $indicator, array $facts): array
    {
        return [];
    }

    /**
     * @param array<string, array> $responses name => Http response (only for this source's requests)
     */
    abstract public function collect(Indicator $indicator, array $responses, array $facts): SourceResult;

    /** Link to the provider's own page for this indicator (shown on the source panel) */
    public function reportUrl(Indicator $indicator): ?string
    {
        return null;
    }
}
