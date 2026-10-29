<?php

namespace app\modules\supplier\components;

use app\modules\inventory\components\DevicePosture;
use DateTimeImmutable;

/**
 * Pure supplier posture evaluation against the supplier checks, with the same
 * results and states as DevicePosture.
 *
 * Each check that applies to the supplier (its tier; DPA checks only when it
 * processes personal data) passes, fails or is not assessed. A manual check
 * takes the result recorded on the supplier; a DPA check reads the DPA answer
 * (signed / not signed / unknown); an assurance check passes while some
 * certificate or report has not expired, fails once all have, and is not
 * assessed when none is recorded.
 */
final class SupplierPosture
{
    public const RESULT_PASS = DevicePosture::RESULT_PASS;
    public const RESULT_FAIL = DevicePosture::RESULT_FAIL;
    public const RESULT_UNKNOWN = DevicePosture::RESULT_UNKNOWN;

    public const STATE_COMPLIANT = DevicePosture::STATE_COMPLIANT;
    public const STATE_NON_COMPLIANT = DevicePosture::STATE_NON_COMPLIANT;
    public const STATE_NOT_ASSESSED = DevicePosture::STATE_NOT_ASSESSED;

    /**
     * @param SupplierCheck[] $checks checks in force (those not applying to the supplier are skipped)
     * @param array<int,string> $results control id => pass | fail for manual checks
     * @param array<int,array{label:string, expires_on:string|null}> $assurance the supplier's assurance entries
     * @return array{state:string, compliant:bool, items:array<int,array{check:SupplierCheck, result:string, detail:string|null}>,
     *               failures:array<int,string>, unknown:array<int,string>}
     */
    public static function evaluate(array $checks, int $tier, bool $personalData, ?string $dpa, array $assurance, array $results, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $items = [];
        foreach ($checks as $check) {
            if (!$check->appliesTo($tier, $personalData)) {
                continue;
            }
            $detail = null;
            if ($check->isDpa()) {
                [$result, $detail] = match ($dpa) {
                    'signed' => [self::RESULT_PASS, 'Data processing agreement signed'],
                    'not_signed' => [self::RESULT_FAIL, 'No data processing agreement signed'],
                    default => [self::RESULT_UNKNOWN, 'Data processing agreement not recorded'],
                };
            } elseif ($check->isAssurance()) {
                [$result, $detail] = self::assuranceResult($assurance, $today);
            } else {
                $result = in_array($results[$check->id] ?? null, [self::RESULT_PASS, self::RESULT_FAIL], true) ? $results[$check->id] : self::RESULT_UNKNOWN;
            }
            $items[$check->id] = ['check' => $check, 'result' => $result, 'detail' => $detail];
        }

        $failures = $unknown = [];
        foreach ($items as $id => $item) {
            if ($item['result'] === self::RESULT_FAIL) {
                $failures[$id] = $item['check']->isManual() ? $item['check']->title . ' failed' : $item['detail'];
            } elseif ($item['result'] === self::RESULT_UNKNOWN) {
                $unknown[$id] = $item['check']->isManual() ? $item['check']->title . ' not assessed' : $item['detail'];
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

    /** @return array{0:string, 1:string} result and detail of an assurance check */
    private static function assuranceResult(array $assurance, string $today): array
    {
        if (!$assurance) {
            return [self::RESULT_UNKNOWN, 'No assurance recorded'];
        }
        $current = array_filter($assurance, static fn($a) => $a['expires_on'] === null || $a['expires_on'] >= $today);
        $fmt = static fn(string $d) => (new DateTimeImmutable($d))->format('j M Y');
        if (!$current) {
            usort($assurance, static fn($a, $b) => strcmp($b['expires_on'], $a['expires_on']));
            return [self::RESULT_FAIL, sprintf('Assurance expired: %s on %s', $assurance[0]['label'], $fmt($assurance[0]['expires_on']))];
        }
        // The longest-lasting one; no expiry date counts as the longest
        usort($current, static fn($a, $b) => strcmp($b['expires_on'] ?? '9999-12-31', $a['expires_on'] ?? '9999-12-31'));
        $best = $current[0];
        return [self::RESULT_PASS, $best['expires_on'] === null ? $best['label'] . ' (no expiry date)' : sprintf('%s until %s', $best['label'], $fmt($best['expires_on']))];
    }
}
