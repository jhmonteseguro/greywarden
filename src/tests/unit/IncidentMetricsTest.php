<?php

namespace tests\unit;

use app\helpers\Dt;
use app\modules\incident\components\IncidentMetrics as M;
use PHPUnit\Framework\TestCase;

class IncidentMetricsTest extends TestCase
{
    private const DETECTED = '2026-03-02 09:15:00';

    public function testDeadlineIs72HoursAfterDetection(): void
    {
        $this->assertSame('2026-03-05 09:15:00', M::deadline(self::DETECTED)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-03 09:15:00', M::deadline(self::DETECTED, 24)->format('Y-m-d H:i:s'));
        $this->assertNull(M::deadline(null));
        $this->assertNull(M::deadline(''));
    }

    public function testNotRequiredWhenFlagIsOff(): void
    {
        $this->assertSame(M::STATE_NOT_REQUIRED, M::deadlineState(false, self::DETECTED, null, '2026-04-01 00:00:00'));
    }

    public function testPendingBeforeDeadline(): void
    {
        $this->assertSame(M::STATE_PENDING, M::deadlineState(true, self::DETECTED, null, '2026-03-05 09:14:59'));
    }

    public function testOverdueAtAndAfterDeadline(): void
    {
        $this->assertSame(M::STATE_OVERDUE, M::deadlineState(true, self::DETECTED, null, '2026-03-05 09:15:00'));
        $this->assertSame(M::STATE_OVERDUE, M::deadlineState(true, self::DETECTED, null, '2026-03-10 00:00:00'));
    }

    public function testMetWhenNotifiedOnTime(): void
    {
        $this->assertSame(M::STATE_MET, M::deadlineState(true, self::DETECTED, '2026-03-04 17:00:00', '2026-03-20 00:00:00'));
        $this->assertSame(M::STATE_MET, M::deadlineState(true, self::DETECTED, '2026-03-05 09:15:00'), 'exactly on the deadline counts as met');
    }

    public function testLateWhenNotifiedAfterDeadline(): void
    {
        $this->assertSame(M::STATE_LATE, M::deadlineState(true, self::DETECTED, '2026-03-05 09:16:00'));
    }

    public function testSecondsRemaining(): void
    {
        $this->assertSame(3600, M::secondsRemaining(self::DETECTED, '2026-03-05 08:15:00'));
        $this->assertSame(-7200, M::secondsRemaining(self::DETECTED, '2026-03-05 11:15:00'));
        $this->assertNull(M::secondsRemaining(null));
    }

    public function testResponseMetrics(): void
    {
        $this->assertSame(2 * 3600 + 15 * 60, M::timeToDetect('2026-03-02 07:00:00', self::DETECTED));
        $this->assertSame(26 * 3600, M::timeToContain(self::DETECTED, '2026-03-03 11:15:00'));
        $this->assertSame(5 * 86400, M::timeToClose(self::DETECTED, '2026-03-07 09:15:00'));
        $this->assertNull(M::timeToContain(self::DETECTED, null));
        $this->assertNull(M::timeToDetect(null, self::DETECTED));
    }

    public function testDurationFormatting(): void
    {
        $this->assertSame('2h 15m', Dt::duration(8100));
        $this->assertSame('1d 2h', Dt::duration(93600));
        $this->assertSame('5d', Dt::duration(5 * 86400));
        $this->assertSame('45m', Dt::duration(2700));
        $this->assertSame('-2h', Dt::duration(-7200));
        $this->assertNull(Dt::duration(null));
    }
}
