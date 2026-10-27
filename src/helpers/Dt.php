<?php

namespace app\helpers;

/**
 * Small date helpers shared by forms and models.
 */
final class Dt
{
    /** DB datetime -> value for <input type="datetime-local"> */
    public static function toInput(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d\TH:i', $ts) : $value;
    }

    public static function today(): string
    {
        return date('Y-m-d');
    }

    public static function daysFromToday(?string $date): ?int
    {
        if (!$date) {
            return null;
        }
        $today = new \DateTimeImmutable('today');
        $target = new \DateTimeImmutable(substr($date, 0, 10));
        return (int) $today->diff($target)->format('%r%a');
    }

    /** Human duration from seconds: "2d 4h", "3h 15m", "12m" */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $negative = $seconds < 0;
        $seconds = abs($seconds);
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        if ($d > 0) {
            $out = $d . 'd' . ($h ? ' ' . $h . 'h' : '');
        } elseif ($h > 0) {
            $out = $h . 'h' . ($m ? ' ' . $m . 'm' : '');
        } else {
            $out = max($m, $seconds > 0 ? 1 : 0) . 'm';
        }
        return ($negative ? '-' : '') . $out;
    }
}
