<?php

namespace app\modules\intel\components;

/**
 * Everything gathered about one indicator in one investigation run.
 */
final class Report
{
    private ?Assessment $assessment = null;

    /**
     * @param array<string, SourceResult> $results source key => result
     * @param array $facts merged facts (see SourceResult)
     */
    public function __construct(
        public readonly Indicator $indicator,
        public readonly array $results,
        public readonly array $facts,
        public readonly float $duration,
    ) {
    }

    public function assessment(): Assessment
    {
        return $this->assessment ??= Assessment::evaluate($this);
    }

    public function result(string $key): ?SourceResult
    {
        return $this->results[$key] ?? null;
    }

    public function fact(string $name, mixed $default = null): mixed
    {
        return $this->facts[$name] ?? $default;
    }

    /** status => count */
    public function statusCounts(): array
    {
        $counts = array_fill_keys([SourceResult::HIT, SourceResult::CLEAN, SourceResult::INFO, SourceResult::ERROR, SourceResult::SKIPPED, SourceResult::UNCONFIGURED], 0);
        foreach ($this->results as $r) {
            $counts[$r->status]++;
        }
        return $counts;
    }

    public function queriedCount(): int
    {
        return count(array_filter($this->results, static fn(SourceResult $r) => !in_array($r->status, [SourceResult::SKIPPED, SourceResult::UNCONFIGURED], true)));
    }

    public function cachedCount(): int
    {
        return count(array_filter($this->results, static fn(SourceResult $r) => $r->cached));
    }

    /** Oldest answer time (unix), i.e. how fresh the report is */
    public function asOf(): int
    {
        $times = array_filter(array_map(static fn(SourceResult $r) => $r->fetchedAt, $this->results));
        return $times ? min($times) : time();
    }
}
