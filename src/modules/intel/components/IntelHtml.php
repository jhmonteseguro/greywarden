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

    /** Country code chip (emoji flags are not available on every platform) */
    public static function flag(?string $cc): string
    {
        if (!$cc || !preg_match('/^[A-Za-z]{2}$/', $cc)) {
            return '';
        }
        return '<span class="gw-intel-cc" title="' . Html::encode(Assessment::countryName($cc) ?? '') . '">' . strtoupper($cc) . '</span>';
    }
}
