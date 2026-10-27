<?php

namespace app\modules\inventory\components;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Pure device compliance/posture evaluation against the device checks.
 *
 * Each check that applies to the device (type + ownership) passes, fails or is
 * not assessed. A manual check takes the result recorded on the device; a
 * patch-age check passes when the device was patched within the check's
 * maximum age, and is not assessed when no patch date is known.
 *
 * The device is non-compliant when any check fails, not assessed when none
 * fails but some have no result, and compliant otherwise.
 */
final class DevicePosture
{
    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';
    public const RESULT_UNKNOWN = 'unknown';

    public const STATE_COMPLIANT = 'compliant';
    public const STATE_NON_COMPLIANT = 'non-compliant';
    public const STATE_NOT_ASSESSED = 'not-assessed';

    public static function stateLabels(): array
    {
        return [self::STATE_COMPLIANT => 'Compliant', self::STATE_NON_COMPLIANT => 'Non-compliant', self::STATE_NOT_ASSESSED => 'Not assessed'];
    }

    public static function resultLabels(): array
    {
        return [self::RESULT_PASS => 'Pass', self::RESULT_FAIL => 'Fail', self::RESULT_UNKNOWN => 'Not assessed'];
    }

    /**
     * @param DeviceCheck[] $checks checks in force (those not applying to the device are skipped)
     * @param array<int,string> $results control id => pass | fail for manual checks
     * @return array{state:string, compliant:bool, items:array<int,array{check:DeviceCheck, result:string, detail:string|null}>,
     *               failures:array<int,string>, unknown:array<int,string>, patchAgeDays:int|null}
     */
    public static function evaluate(array $checks, ?string $deviceType, ?string $ownership, array $results,
                                    DateTimeInterface|string|null $lastPatched, DateTimeInterface|string|null $today = null): array
    {
        $age = self::patchAgeDays($lastPatched, $today);
        $items = $failures = $unknown = [];
        foreach ($checks as $check) {
            if (!$check->appliesTo($deviceType, $ownership)) {
                continue;
            }
            $detail = null;
            if ($check->isPatchAge()) {
                if ($age === null) {
                    $result = self::RESULT_UNKNOWN;
                    $detail = 'No patch date recorded';
                } else {
                    $result = $age <= $check->maxAgeDays ? self::RESULT_PASS : self::RESULT_FAIL;
                    $detail = sprintf('Last patched %d days ago (max %d)', $age, $check->maxAgeDays);
                }
            } else {
                $result = in_array($results[$check->id] ?? null, [self::RESULT_PASS, self::RESULT_FAIL], true) ? $results[$check->id] : self::RESULT_UNKNOWN;
            }
            $items[$check->id] = ['check' => $check, 'result' => $result, 'detail' => $detail];
            if ($result === self::RESULT_FAIL) {
                $failures[$check->id] = $check->isPatchAge() ? $detail : $check->title . ' failed';
            } elseif ($result === self::RESULT_UNKNOWN) {
                $unknown[$check->id] = $check->title . ' not assessed';
            }
        }

        $state = $failures ? self::STATE_NON_COMPLIANT : ($unknown ? self::STATE_NOT_ASSESSED : self::STATE_COMPLIANT);
        return [
            'state' => $state,
            'compliant' => $state === self::STATE_COMPLIANT,
            'items' => $items,
            'failures' => $failures,
            'unknown' => $unknown,
            'patchAgeDays' => $age,
        ];
    }

    public static function patchAgeDays(DateTimeInterface|string|null $lastPatched, DateTimeInterface|string|null $today = null): ?int
    {
        if ($lastPatched === null || $lastPatched === '') {
            return null;
        }
        $patched = new DateTimeImmutable(is_string($lastPatched) ? substr($lastPatched, 0, 10) : $lastPatched->format('Y-m-d'));
        $todayDate = new DateTimeImmutable($today === null ? 'today' : (is_string($today) ? substr($today, 0, 10) : $today->format('Y-m-d')));
        return (int) $patched->diff($todayDate)->format('%r%a');
    }

    /** Oldest acceptable patch date for "today" */
    public static function patchCutoff(int $maxPatchAgeDays, ?string $today = null): string
    {
        return (new DateTimeImmutable($today ?? 'today'))->modify("-{$maxPatchAgeDays} days")->format('Y-m-d');
    }
}
