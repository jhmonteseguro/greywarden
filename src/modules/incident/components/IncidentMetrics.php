<?php

namespace app\modules\incident\components;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Pure functions for incident timing: regulator deadline and response metrics.
 * Kept free of ActiveRecord so it is trivially unit-testable.
 */
final class IncidentMetrics
{
    public const STATE_NOT_REQUIRED = 'not_required';
    public const STATE_PENDING = 'pending';   // not yet notified, deadline in the future
    public const STATE_OVERDUE = 'overdue';   // not notified and deadline passed
    public const STATE_MET = 'met';           // notified on or before the deadline
    public const STATE_LATE = 'late';         // notified after the deadline

    public static function toDate(DateTimeInterface|string|null $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** Deadline = detected_at + N hours (default 72). */
    public static function deadline(DateTimeInterface|string|null $detectedAt, int $hours = 72): ?DateTimeImmutable
    {
        $detected = self::toDate($detectedAt);
        return $detected?->add(new DateInterval('PT' . $hours . 'H'));
    }

    public static function deadlineState(
        bool $required,
        DateTimeInterface|string|null $detectedAt,
        DateTimeInterface|string|null $notifiedAt,
        DateTimeInterface|string|null $now = null,
        int $hours = 72
    ): string {
        $deadline = self::deadline($detectedAt, $hours);
        if (!$required || $deadline === null) {
            return self::STATE_NOT_REQUIRED;
        }
        $notified = self::toDate($notifiedAt);
        if ($notified !== null) {
            return $notified <= $deadline ? self::STATE_MET : self::STATE_LATE;
        }
        $now = self::toDate($now) ?? new DateTimeImmutable();
        return $now >= $deadline ? self::STATE_OVERDUE : self::STATE_PENDING;
    }

    /** Seconds until the deadline (negative when passed); null if no deadline. */
    public static function secondsRemaining(DateTimeInterface|string|null $detectedAt, DateTimeInterface|string|null $now = null, int $hours = 72): ?int
    {
        $deadline = self::deadline($detectedAt, $hours);
        if ($deadline === null) {
            return null;
        }
        $now = self::toDate($now) ?? new DateTimeImmutable();
        return $deadline->getTimestamp() - $now->getTimestamp();
    }

    /** Seconds between two points in time, or null if either is missing / negative order is kept. */
    public static function duration(DateTimeInterface|string|null $from, DateTimeInterface|string|null $to): ?int
    {
        $a = self::toDate($from);
        $b = self::toDate($to);
        if ($a === null || $b === null) {
            return null;
        }
        return $b->getTimestamp() - $a->getTimestamp();
    }

    /** Time to detect = detected - occurred */
    public static function timeToDetect($occurredAt, $detectedAt): ?int
    {
        return self::duration($occurredAt, $detectedAt);
    }

    /** Time to contain = contained - detected */
    public static function timeToContain($detectedAt, $containedAt): ?int
    {
        return self::duration($detectedAt, $containedAt);
    }

    /** Time to close = closed - detected */
    public static function timeToClose($detectedAt, $closedAt): ?int
    {
        return self::duration($detectedAt, $closedAt);
    }
}
