<?php

namespace app\modules\report\components;

use DateTimeImmutable;

/**
 * Reporting periods: calendar months and quarters, the year to date and
 * custom date ranges. Pure date arithmetic on Y-m-d strings, so it is easy to
 * test; "today" can be passed in.
 */
final class ReportPeriod
{
    public const MONTH = 'month';
    public const QUARTER = 'quarter';
    public const YEAR = 'year';
    public const CUSTOM = 'custom';

    /** The longest custom period, in days (a little over a year) */
    public const MAX_DAYS = 400;

    public static function typeOptions(): array
    {
        return [
            self::MONTH => 'Month',
            self::QUARTER => 'Quarter',
            self::YEAR => 'Year to date',
            self::CUSTOM => 'Custom',
        ];
    }

    /**
     * Ready-made periods for the create form, most likely first: the month and
     * quarter just finished, the ones in progress and the year to date.
     * @return array<string,array{type:string, start:string, end:string, label:string}> key => period
     */
    public static function presets(?string $today = null): array
    {
        $today = new DateTimeImmutable($today ?? date('Y-m-d'));
        $lastMonth = $today->modify('first day of last month');
        $quarterStart = self::quarterStart($today);
        $lastQuarter = $quarterStart->modify('-3 months');
        $out = [];
        foreach ([
            'last-month' => [self::MONTH, $lastMonth, $lastMonth->modify('last day of this month'), 'Last month'],
            'last-quarter' => [self::QUARTER, $lastQuarter, $quarterStart->modify('-1 day'), 'Last quarter'],
            'this-month' => [self::MONTH, $today->modify('first day of this month'), $today, 'This month so far'],
            'this-quarter' => [self::QUARTER, $quarterStart, $today, 'This quarter so far'],
            'year' => [self::YEAR, $today->setDate((int) $today->format('Y'), 1, 1), $today, ''],
        ] as $key => [$type, $start, $end, $label]) {
            $out[$key] = ['type' => $type, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
                'label' => $type === self::YEAR ? self::name($type, $start->format('Y-m-d'), $end->format('Y-m-d'))
                    : $label . ': ' . self::name($type, $start->format('Y-m-d'), $end->format('Y-m-d'))];
        }
        return $out;
    }

    /** First day of the calendar quarter holding $date */
    public static function quarterStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $month = intdiv((int) $date->format('n') - 1, 3) * 3 + 1;
        return $date->setDate((int) $date->format('Y'), $month, 1);
    }

    /** Whole calendar month / quarter from its first to its last day */
    public static function isWhole(string $type, string $start, string $end): bool
    {
        $s = new DateTimeImmutable($start);
        return match ($type) {
            self::MONTH => $s->format('j') === '1' && $end === $s->modify('last day of this month')->format('Y-m-d'),
            self::QUARTER => self::quarterStart($s) == $s && $end === $s->modify('+3 months -1 day')->format('Y-m-d'),
            default => false,
        };
    }

    /**
     * Short name of a period: "October 2026", "Q3 2026", "Year to date 2026",
     * with "so far" for a month or quarter still in progress, or the dates.
     */
    public static function name(string $type, string $start, string $end): string
    {
        $s = new DateTimeImmutable($start);
        $year = $s->format('Y');
        $fromMonthStart = $s->format('j') === '1';
        return match (true) {
            $type === self::MONTH && $fromMonthStart => $s->format('F Y') . (self::isWhole($type, $start, $end) ? '' : ' (to ' . self::day($end) . ')'),
            $type === self::QUARTER && self::quarterStart($s) == $s => 'Q' . intdiv((int) $s->format('n') + 2, 3) . ' ' . $year
                . (self::isWhole($type, $start, $end) ? '' : ' (to ' . self::day($end) . ')'),
            $type === self::YEAR && $s->format('m-d') === '01-01' => 'Year to date ' . $year . ' (to ' . self::day($end) . ')',
            default => self::range($start, $end),
        };
    }

    /** "1 Oct – 15 Oct 2026", or "1 Dec 2025 – 15 Jan 2026" across a year end */
    public static function range(string $start, string $end): string
    {
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        return $s->format('Y') === $e->format('Y')
            ? $s->format('j M') . ' – ' . $e->format('j M Y')
            : $s->format('j M Y') . ' – ' . $e->format('j M Y');
    }

    private static function day(string $date): string
    {
        return (new DateTimeImmutable($date))->format('j M');
    }

    /** The default report title for a period */
    public static function title(string $type, string $start, string $end): string
    {
        return 'Security posture report · ' . self::name($type, $start, $end);
    }

    /**
     * The period just before, to compare against: the previous month or
     * quarter (to the same day when the period is still in progress), the same
     * part of last year for the year to date, or the same number of days.
     * @return array{0:string, 1:string} [start, end]
     */
    public static function previous(string $type, string $start, string $end): array
    {
        $s = new DateTimeImmutable($start);
        $e = new DateTimeImmutable($end);
        $shift = match ($type) {
            self::MONTH => '-1 month',
            self::QUARTER => '-3 months',
            self::YEAR => '-1 year',
            default => null,
        };
        if ($shift !== null && $s->format('j') === '1') {
            $prevStart = $s->modify($shift);
            $prevEnd = self::isWhole($type, $start, $end)
                ? $s->modify('-1 day')
                : self::clampShift($e, $shift, $s->modify('-1 day'));
            return [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];
        }
        $days = self::days($start, $end);
        return [$s->modify("-$days days")->format('Y-m-d'), $s->modify('-1 day')->format('Y-m-d')];
    }

    /** $date moved by $shift, but never past $max (31 Mar - 1 month is 28 Feb, not 3 Mar) */
    private static function clampShift(DateTimeImmutable $date, string $shift, DateTimeImmutable $max): DateTimeImmutable
    {
        $moved = $date->modify($shift);
        if ((int) $moved->format('j') !== (int) $date->format('j')) {
            $moved = $moved->modify('last day of previous month');
        }
        return $moved > $max ? $max : $moved;
    }

    /** Days in the period, both ends included */
    public static function days(string $start, string $end): int
    {
        return (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
    }

    /** How far "Looking ahead" looks past the period end: 90 days after a quarter or year, 30 otherwise */
    public static function lookaheadDays(string $type): int
    {
        return in_array($type, [self::QUARTER, self::YEAR], true) ? 90 : 30;
    }
}
