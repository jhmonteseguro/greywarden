<?php

namespace app\modules\cloud\components;

use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\components\OsLifecycle;
use DateTimeImmutable;

/**
 * Pure cloud asset / cloud account posture evaluation against the cloud checks,
 * with the same results and states as DevicePosture.
 *
 * Each check that applies to the asset (resource type) or account passes, fails
 * or is not assessed. A manual check takes the result recorded on the record; a
 * supported-OS check fails once an instance's OS is past its end of support
 * (OsLifecycle) and is not assessed when its OS / version has no known date; an
 * encryption check reads the asset's "encrypted at rest" answer (yes / no /
 * unknown).
 */
final class CloudPosture
{
    public const RESULT_PASS = DevicePosture::RESULT_PASS;
    public const RESULT_FAIL = DevicePosture::RESULT_FAIL;
    public const RESULT_UNKNOWN = DevicePosture::RESULT_UNKNOWN;

    public const STATE_COMPLIANT = DevicePosture::STATE_COMPLIANT;
    public const STATE_NON_COMPLIANT = DevicePosture::STATE_NON_COMPLIANT;
    public const STATE_NOT_ASSESSED = DevicePosture::STATE_NOT_ASSESSED;

    /**
     * @param CloudCheck[] $checks checks in force (those not applying to the asset are skipped)
     * @param array<int,string> $results control id => pass | fail for manual checks
     * @return array{state:string, compliant:bool, items:array<int,array{check:CloudCheck, result:string, detail:string|null}>,
     *               failures:array<int,string>, unknown:array<int,string>}
     */
    public static function evaluateAsset(array $checks, ?string $resourceType, array $results, ?string $os = null, ?string $osVersion = null,
                                         ?string $encryption = null, ?string $today = null): array
    {
        $osSupport = null;
        $items = [];
        foreach ($checks as $check) {
            if (!$check->appliesToAsset($resourceType)) {
                continue;
            }
            $detail = null;
            if ($check->isOsSupport()) {
                $osSupport ??= OsLifecycle::evaluate($os, $osVersion, $today);
                $osName = trim($os . ' ' . $osVersion) ?: 'OS';
                if ($osSupport['state'] === OsLifecycle::UNKNOWN) {
                    $result = self::RESULT_UNKNOWN;
                    $detail = $os ? sprintf('No end-of-support date known for %s', $osName) : 'No operating system recorded';
                } else {
                    $result = $osSupport['state'] === OsLifecycle::UNSUPPORTED ? self::RESULT_FAIL : self::RESULT_PASS;
                    $detail = sprintf('%s: security updates %s %s%s', $osName, $result === self::RESULT_FAIL ? 'ended' : 'until',
                        (new DateTimeImmutable($osSupport['endDate']))->format('j M Y'), $osSupport['estimated'] ? ' (estimated)' : '');
                }
            } elseif ($check->isEncryption()) {
                [$result, $detail] = match ($encryption) {
                    'yes' => [self::RESULT_PASS, 'Encrypted at rest'],
                    'no' => [self::RESULT_FAIL, 'Not encrypted at rest'],
                    default => [self::RESULT_UNKNOWN, 'Encryption at rest unknown'],
                };
            } else {
                $result = self::manualResult($check, $results);
            }
            $items[$check->id] = ['check' => $check, 'result' => $result, 'detail' => $detail];
        }
        return self::summarise($items);
    }

    /**
     * @param CloudCheck[] $checks checks in force (only those recorded on accounts count)
     * @param array<int,string> $results control id => pass | fail
     */
    public static function evaluateAccount(array $checks, array $results): array
    {
        $items = [];
        foreach ($checks as $check) {
            if ($check->appliesToAccounts()) {
                $items[$check->id] = ['check' => $check, 'result' => self::manualResult($check, $results), 'detail' => null];
            }
        }
        return self::summarise($items);
    }

    private static function manualResult(CloudCheck $check, array $results): string
    {
        return in_array($results[$check->id] ?? null, [self::RESULT_PASS, self::RESULT_FAIL], true) ? $results[$check->id] : self::RESULT_UNKNOWN;
    }

    /** Non-compliant when any check fails, not assessed when none fails but some have no result, compliant otherwise */
    private static function summarise(array $items): array
    {
        $failures = $unknown = [];
        foreach ($items as $id => $item) {
            if ($item['result'] === self::RESULT_FAIL) {
                $failures[$id] = $item['check']->isManual() ? $item['check']->title . ' failed' : $item['detail'];
            } elseif ($item['result'] === self::RESULT_UNKNOWN) {
                $unknown[$id] = $item['check']->title . ' not assessed';
            }
        }
        $state = $failures ? self::STATE_NON_COMPLIANT : ($unknown ? self::STATE_NOT_ASSESSED : self::STATE_COMPLIANT);
        return [
            'state' => $state,
            'compliant' => $state === self::STATE_COMPLIANT,
            'items' => $items,
            'failures' => $failures,
            'unknown' => $unknown,
        ];
    }
}
