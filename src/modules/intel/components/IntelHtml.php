<?php

namespace app\modules\intel\components;

use app\modules\intel\components\sources\Source;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * Rendering helpers for intel pages: values from sources become links where
 * that helps (ASNs and IPs to their report, URLs, mail addresses).
 */
final class IntelHtml
{
    public const CATEGORY_ICONS = [
        Source::CAT_FEEDS => 'collection',
        Source::CAT_REPUTATION => 'people',
        Source::CAT_DNSBL => 'envelope-x',
        Source::CAT_EXPOSURE => 'hdd-network',
        Source::CAT_NETWORK => 'diagram-3',
        Source::CAT_REGISTRY => 'journal-text',
    ];

    public const STATUS_LABELS = [
        SourceResult::HIT => 'Hit',
        SourceResult::CLEAN => 'Clean',
        SourceResult::INFO => 'Data',
        SourceResult::ERROR => 'Error',
        SourceResult::SKIPPED => 'Skipped',
        SourceResult::UNCONFIGURED => 'No key',
    ];

    /** One value (or a list of values) from a source row */
    public static function value(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(static fn($v) => '<span class="gw-intel-chip">' . self::value($v) . '</span>', array_values($value));
            return '<span class="gw-intel-chips">' . implode('', $items) . '</span>';
        }
        $value = (string) $value;
        if (preg_match('~^https?://[^\s"<>]+$~i', $value)) {
            return Html::a(Html::encode(preg_replace('~^https?://(www\.)?~i', '', rtrim($value, '/'))), $value, ['target' => '_blank', 'rel' => 'noopener noreferrer nofollow', 'class' => 'gw-intel-ext']);
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Html::mailto(Html::encode($value), $value, ['class' => 'gw-mono']);
        }
        // "AS15169" or "AS15169 · Google LLC" -> link the ASN
        if (preg_match('/^AS(\d{1,10})(\b.*)$/s', $value, $m)) {
            return Html::a('AS' . $m[1], Url::to(['/intel/investigate/asn', 'asn' => 'AS' . $m[1]]), ['class' => 'gw-intel-pivot']) . Html::encode($m[2]);
        }
        if (IpAddress::isValid($value)) {
            return Html::a(Html::encode($value), Url::to(['/intel/investigate/ip', 'ip' => IpAddress::normalize($value)]), ['class' => 'gw-intel-pivot']);
        }
        return Html::encode($value);
    }

    /** Status pill for a source answer */
    public static function status(SourceResult $result): string
    {
        return \app\helpers\Badge::render('source-status', $result->status, self::STATUS_LABELS[$result->status] ?? null);
    }

    public static function duration(float $seconds): string
    {
        return $seconds < 1 ? round($seconds * 1000) . ' ms' : number_format($seconds, 1) . ' s';
    }

    /** "3 min ago" style age of a unix time */
    public static function age(?int $time): string
    {
        if (!$time) {
            return 'never';
        }
        $s = max(0, time() - $time);
        return match (true) {
            $s < 60 => 'just now',
            $s < 3600 => floor($s / 60) . ' min ago',
            $s < 86400 => floor($s / 3600) . ' h ago',
            default => floor($s / 86400) . ' d ago',
        };
    }

    /** Rough rendered height of a source panel in pixels, for balancing columns */
    public static function weight(SourceResult $result): int
    {
        $h = 165;
        foreach ($result->rows as $value) {
            $h += 25 * (is_array($value) ? max(1, (int) ceil(count($value) / 6)) : 1);
        }
        foreach ($result->lists as $table) {
            $h += 75 + min(340, 32 * count($table['rows'] ?? []));
        }
        return $h + ($result->raw ? 35 : 0);
    }

    /**
     * Splits panels into balanced columns: tallest first, each to the shortest
     * column so far, then each column back in the original order. Keys map to
     * their position in the original order.
     * @param SourceResult[] $results
     * @return array<int, array<string, int>>
     */
    public static function stacks(array $results, int $columns = 2): array
    {
        $positions = array_flip(array_keys($results));
        $weights = array_map(self::weight(...), $results);
        uksort($weights, static fn($a, $b) => $weights[$b] <=> $weights[$a] ?: $positions[$a] <=> $positions[$b]);

        $stacks = array_fill(0, $columns, []);
        $heights = array_fill(0, $columns, 0);
        foreach ($weights as $key => $weight) {
            $col = array_search(min($heights), $heights, true);
            $stacks[$col][$key] = $positions[$key];
            $heights[$col] += $weight;
        }
        foreach ($stacks as &$stack) {
            asort($stack);
        }
        return $stacks;
    }

    /** Country code chip (emoji flags are not available on every platform) */
    public static function flag(?string $cc): string
    {
        if (!$cc || !preg_match('/^[A-Za-z]{2}$/', $cc)) {
            return '';
        }
        return '<span class="gw-intel-cc" title="' . Html::encode(Assessment::countryName($cc) ?? '') . '">' . strtoupper($cc) . '</span>';
    }
}
