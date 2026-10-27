<?php

namespace app\modules\intel\components;

/**
 * What one intelligence source said about an indicator.
 *
 *  status   hit | clean | info | error | skipped | unconfigured
 *  summary  one line for the source strip, e.g. "Listed: SBL256894"
 *  rows     label => value (string, or list of strings) for the source panel
 *  signals  risk evidence: ['weight' => 0-100, 'tone' => critical|high|medium|low|info|success, 'label' => ..., 'detail' => ..., 'group' => ?string]
 *           weight < 0 = evidence of a known-good service (lowers the score);
 *           signals sharing a group (tor, proxy, scanner, drop) describe one fact: only the strongest counts
 *  facts    normalised facts the assessment merges across sources:
 *           asn, as_name, org, network, country, country_name, city, region, lat, lon, hostname,
 *           abuse_email, network_type, is_tor, is_proxy, is_vpn, is_hosting, ports[], vulns[], tags[], hostnames[]
 *  lists    named tables for the panel: name => ['columns' => [...], 'rows' => [[...], ...]]
 *  raw      raw text (WHOIS) or pretty-printed JSON
 */
final class SourceResult
{
    public const HIT = 'hit';
    public const CLEAN = 'clean';
    public const INFO = 'info';
    public const ERROR = 'error';
    public const SKIPPED = 'skipped';
    public const UNCONFIGURED = 'unconfigured';

    public array $rows = [];
    public array $signals = [];
    public array $facts = [];
    public array $lists = [];
    public ?string $raw = null;
    public float $time = 0.0;
    public ?int $fetchedAt = null;
    public bool $cached = false;

    public function __construct(
        public string $status,
        public string $summary = '',
    ) {
    }

    public static function hit(string $summary): self
    {
        return new self(self::HIT, $summary);
    }

    public static function clean(string $summary = 'Not listed'): self
    {
        return new self(self::CLEAN, $summary);
    }

    public static function info(string $summary): self
    {
        return new self(self::INFO, $summary);
    }

    public static function error(string $summary): self
    {
        return new self(self::ERROR, $summary);
    }

    public static function skipped(string $summary): self
    {
        return new self(self::SKIPPED, $summary);
    }

    public static function unconfigured(string $summary): self
    {
        return new self(self::UNCONFIGURED, $summary);
    }

    public function signal(int $weight, string $tone, string $label, ?string $detail = null, ?string $group = null): self
    {
        $this->signals[] = ['weight' => max(-100, min(100, $weight)), 'tone' => $tone, 'label' => $label, 'detail' => $detail, 'group' => $group];
        return $this;
    }

    public function row(string $label, mixed $value): self
    {
        if ($value !== null && $value !== '' && $value !== []) {
            $this->rows[$label] = $value;
        }
        return $this;
    }

    public function fact(string $name, mixed $value): self
    {
        if ($value !== null && $value !== '' && $value !== []) {
            $this->facts[$name] = $value;
        }
        return $this;
    }

    public function table(string $name, array $columns, array $rows): self
    {
        if ($rows) {
            $this->lists[$name] = ['columns' => $columns, 'rows' => $rows];
        }
        return $this;
    }

    public function rawJson(mixed $data): self
    {
        $this->raw = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
        if ($this->raw !== null && strlen($this->raw) > 65536) {
            $this->raw = substr($this->raw, 0, 65536) . "\n… (truncated)";
        }
        return $this;
    }

    /** Did the source answer (as opposed to failing / being skipped)? */
    public function answered(): bool
    {
        return in_array($this->status, [self::HIT, self::CLEAN, self::INFO], true);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        $r = new self($data['status'] ?? self::ERROR, $data['summary'] ?? '');
        foreach (['rows', 'signals', 'facts', 'lists', 'raw', 'time', 'fetchedAt'] as $k) {
            if (array_key_exists($k, $data)) {
                $r->$k = $data[$k];
            }
        }
        return $r;
    }
}
