<?php

namespace app\modules\control\components;

use app\modules\control\models\Control;
use app\modules\control\models\FrameworkRequirement;
use yii\db\Query;

/**
 * Rolls the Control Register up to framework requirements (the Statement of
 * Applicability for ISO 27001, readiness for Cyber Essentials).
 *
 * A requirement takes the best state of its linked controls (see
 * Control::frameworkState()): met > partial > planned; with no control it is
 * a gap, and requirements marked not applicable are excluded. A requirement
 * whose controls cover only part of it is at best partly met.
 */
final class FrameworkCoverage
{
    public const STATE_MET = 'met';
    public const STATE_PARTIAL = 'partial';
    public const STATE_PLANNED = 'planned';
    public const STATE_GAP = 'gap';
    public const STATE_EXCLUDED = 'excluded';

    private const RANK = [self::STATE_MET => 3, self::STATE_PARTIAL => 2, self::STATE_PLANNED => 1];

    public static function stateLabels(): array
    {
        return [
            self::STATE_MET => 'Met',
            self::STATE_PARTIAL => 'Partly met',
            self::STATE_PLANNED => 'Planned',
            self::STATE_GAP => 'Gap',
            self::STATE_EXCLUDED => 'Not applicable',
        ];
    }

    /**
     * @param int[]|null $requirementIds limit to these requirements
     * @return array<int,array{requirement:FrameworkRequirement, state:string, controls:Control[], unverified:bool}>
     *         requirement id => row, in catalogue order
     */
    public static function rows(string $framework, ?array $requirementIds = null): array
    {
        $requirements = FrameworkRequirement::find()->where(['framework' => $framework])
            ->andFilterWhere(['id' => $requirementIds])->orderBy('sort_order')->indexBy('id')->all();
        if (!$requirements) {
            return [];
        }
        $pairs = (new Query())->select(['l.requirement_id', 'l.control_id'])->from(['l' => 'control_framework_requirement'])
            ->where(['l.requirement_id' => array_keys($requirements)])->all();
        $controls = $pairs ? Control::find()->where(['id' => array_unique(array_column($pairs, 'control_id'))])
            ->orderBy('reference')->indexBy('id')->all() : [];
        $linked = [];
        foreach ($pairs as $p) {
            $control = $controls[(int) $p['control_id']] ?? null;
            if ($control && $control->frameworkState() !== null) {
                $linked[(int) $p['requirement_id']][] = $control;
            }
        }

        $rows = [];
        foreach ($requirements as $id => $requirement) {
            $rows[$id] = ['requirement' => $requirement] + self::evaluate((bool) $requirement->applicable, $linked[$id] ?? [], (bool) $requirement->partial_coverage);
        }
        return $rows;
    }

    /**
     * @param Control[] $controls linked controls
     * @param bool $partialCoverage the controls cover only part of the requirement
     * @return array{state:string, controls:Control[], unverified:bool}
     */
    public static function evaluate(bool $applicable, array $controls, bool $partialCoverage = false): array
    {
        $state = self::STATE_GAP;
        foreach ($controls as $control) {
            $s = $control->frameworkState();
            if ($s !== null && self::RANK[$s] > (self::RANK[$state] ?? 0)) {
                $state = $s;
            }
        }
        if ($partialCoverage && $state === self::STATE_MET) {
            $state = self::STATE_PARTIAL;
        }
        // Met only by controls nobody has tested yet
        $unverified = $state === self::STATE_MET && !array_filter($controls, static fn(Control $c) => $c->frameworkState() === Control::FRAMEWORK_MET
            && $c->effectiveness === Control::EFFECTIVENESS_EFFECTIVE);
        return [
            'state' => $applicable ? $state : self::STATE_EXCLUDED,
            'controls' => $controls,
            'unverified' => $applicable && $unverified,
        ];
    }

    /** @return array{total:int, applicable:int, met:int, partial:int, planned:int, gap:int, excluded:int, unverified:int, percent:int} */
    public static function summary(array $rows): array
    {
        $out = array_fill_keys(array_keys(self::stateLabels()), 0) + ['unverified' => 0];
        foreach ($rows as $row) {
            $out[$row['state']]++;
            $out['unverified'] += $row['unverified'] ? 1 : 0;
        }
        $out['total'] = count($rows);
        $out['applicable'] = $out['total'] - $out[self::STATE_EXCLUDED];
        $out['percent'] = $out['applicable'] ? (int) floor($out[self::STATE_MET] / $out['applicable'] * 100) : 0;
        return $out;
    }

    /** Rows grouped by theme: group name => rows */
    public static function byGroup(array $rows): array
    {
        $out = [];
        foreach ($rows as $id => $row) {
            $out[$row['requirement']->group_name][$id] = $row;
        }
        return $out;
    }
}
