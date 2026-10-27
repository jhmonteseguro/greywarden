<?php

namespace app\modules\attack\components;

use app\modules\attack\models\AttackMitigation;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;
use yii\db\Expression;
use yii\db\Query;

/**
 * Joins the ATT&CK catalogue to Greywarden's records:
 *
 *  - observed:  techniques recorded on (non-archived) incidents
 *  - modelled:  techniques mapped to open (non-archived) risks
 *  - adoption:  mitigations recorded on (non-archived) controls and decisions:
 *               "in place" needs an implemented control that has not tested
 *               partially effective / ineffective; "planned" is any other live
 *               control, or an approved / implemented decision; "proposed" a
 *               proposed decision; anything else is a gap
 */
final class Coverage
{
    public const STATE_IMPLEMENTED = 'implemented';
    public const STATE_APPROVED = 'approved';
    public const STATE_PROPOSED = 'proposed';
    public const STATE_GAP = 'gap';

    /** MITRE entries that are guidance, not controls: never reported as a gap */
    public const NOT_ACTIONABLE = ['M1055']; // Do Not Mitigate

    public const LAYER_INCIDENTS = 'incidents';
    public const LAYER_RISKS = 'risks';

    public static function stateLabels(): array
    {
        return [
            self::STATE_IMPLEMENTED => 'In place',
            self::STATE_APPROVED => 'Planned',
            self::STATE_PROPOSED => 'Proposed',
            self::STATE_GAP => 'Gap',
        ];
    }

    public static function layerLabels(): array
    {
        return [self::LAYER_INCIDENTS => 'Observed in incidents', self::LAYER_RISKS => 'Mapped to open risks'];
    }

    /**
     * Distinct record counts per technique for a layer.
     *
     * @param bool $rollUp count sub-technique hits against their parent technique
     * @param string|null $since only incidents detected on/after this datetime
     * @return array<int,int> technique id => count
     */
    public static function counts(string $layer, bool $rollUp = false, ?string $since = null): array
    {
        $key = $rollUp ? new Expression('COALESCE(t.parent_id, t.id)') : 'l.technique_id';
        // column() returns the FIRST selected column, so the count goes first
        $query = (new Query())->innerJoin(['t' => 'attack_technique'], 't.id = l.technique_id')->groupBy('tid');
        if ($layer === self::LAYER_RISKS) {
            $query->select(['n' => 'COUNT(DISTINCT l.risk_id)'])->from(['l' => 'risk_attack_technique'])
                ->innerJoin(['r' => 'risk'], 'r.id = l.risk_id')
                ->where(['r.archived_at' => null])->andWhere(['<>', 'r.status', Risk::STATUS_CLOSED]);
        } else {
            $query->select(['n' => 'COUNT(DISTINCT l.incident_id)'])->from(['l' => 'incident_attack_technique'])
                ->innerJoin(['i' => 'incident'], 'i.id = l.incident_id')
                ->where(['i.archived_at' => null])
                ->andFilterWhere(['>=', 'i.detected_at', $since]);
        }
        return array_map('intval', $query->addSelect(['tid' => $key])->indexBy('tid')->column());
    }

    /**
     * Distinct incidents per tactic (via the techniques recorded on them).
     * @return array<int,int> tactic id => incident count
     */
    public static function incidentsPerTactic(?string $since = null): array
    {
        return array_map('intval', (new Query())
            ->select(['n' => 'COUNT(DISTINCT l.incident_id)', 'tt.tactic_id'])
            ->from(['l' => 'incident_attack_technique'])
            ->innerJoin(['i' => 'incident'], 'i.id = l.incident_id')
            ->innerJoin(['tt' => 'attack_technique_tactic'], 'tt.technique_id = l.technique_id')
            ->where(['i.archived_at' => null])
            ->andFilterWhere(['>=', 'i.detected_at', $since])
            ->groupBy('tt.tactic_id')->indexBy('tactic_id')->column());
    }

    /** Adoption state a control gives the mitigations it records (null = none) */
    public static function controlState(Control $control): ?string
    {
        if ($control->getIsArchived() || $control->status === Control::STATUS_RETIRED) {
            return null;
        }
        return $control->status === Control::STATUS_IMPLEMENTED && !in_array($control->effectiveness, Control::WEAK_RESULTS, true)
            ? self::STATE_IMPLEMENTED : self::STATE_APPROVED;
    }

    /** Adoption state a decision gives the mitigations it records (null = none) */
    public static function decisionState(Decision $decision): ?string
    {
        if ($decision->getIsArchived()) {
            return null;
        }
        return match ($decision->status) {
            Decision::STATUS_APPROVED, Decision::STATUS_IMPLEMENTED => self::STATE_APPROVED,
            Decision::STATUS_PROPOSED => self::STATE_PROPOSED,
            default => null,
        };
    }

    /**
     * Best adoption state of every mitigation recorded on a control or decision.
     * @return array<int,array{state:string, controls:Control[], decisions:Decision[]}> mitigation id => adoption
     */
    public static function adoption(): array
    {
        $rank = [self::STATE_IMPLEMENTED => 3, self::STATE_APPROVED => 2, self::STATE_PROPOSED => 1];
        $out = [];
        $add = static function (int $mid, string $state, string $kind, object $record) use (&$out, $rank) {
            $out[$mid] ??= ['state' => $state, 'controls' => [], 'decisions' => []];
            if ($rank[$state] > $rank[$out[$mid]['state']]) {
                $out[$mid]['state'] = $state;
            }
            $out[$mid][$kind][] = $record;
        };

        $controls = Control::find()->where(['archived_at' => null])->andWhere(['<>', 'status', Control::STATUS_RETIRED])
            ->andWhere(['id' => (new Query())->select('control_id')->from('control_attack_mitigation')])
            ->orderBy(['reference' => SORT_ASC])->indexBy('id')->all();
        if ($controls) {
            foreach ((new Query())->select(['control_id', 'mitigation_id'])->from('control_attack_mitigation')
                         ->where(['control_id' => array_keys($controls)])->orderBy('control_id')->all() as $p) {
                $control = $controls[(int) $p['control_id']];
                $add((int) $p['mitigation_id'], self::controlState($control), 'controls', $control);
            }
        }

        $decisions = Decision::find()
            ->where(['archived_at' => null, 'status' => [Decision::STATUS_PROPOSED, Decision::STATUS_APPROVED, Decision::STATUS_IMPLEMENTED]])
            ->andWhere(['id' => (new Query())->select('decision_id')->from('decision_attack_mitigation')])
            ->orderBy(['decision_date' => SORT_DESC, 'id' => SORT_DESC])->indexBy('id')->all();
        if ($decisions) {
            $pairs = (new Query())->select(['decision_id', 'mitigation_id'])->from('decision_attack_mitigation')
                ->where(['decision_id' => array_keys($decisions)])->all();
            foreach ($decisions as $decisionId => $decision) {
                foreach ($pairs as $p) {
                    if ((int) $p['decision_id'] === $decisionId) {
                        $add((int) $p['mitigation_id'], self::decisionState($decision), 'decisions', $decision);
                    }
                }
            }
        }
        return $out;
    }

    /**
     * MITRE-recommended mitigations for a set of techniques, with adoption state.
     * Sub-techniques also inherit the mitigations mapped to their parent.
     *
     * @param int[] $techniqueIds
     * @param array|null $adoption precomputed adoption() (when called in a loop)
     * @return array<int,array{mitigation:AttackMitigation, techniques:string[], state:string, controls:Control[], decisions:Decision[]}>
     *         sorted gaps first, then by the number of techniques addressed
     */
    public static function mitigationsFor(array $techniqueIds, ?array $adoption = null): array
    {
        if (!$techniqueIds) {
            return [];
        }
        $techniques = (new Query())->select(['id', 'external_id', 'parent_id'])->from('attack_technique')
            ->where(['id' => $techniqueIds])->indexBy('id')->all();
        $lookup = [];
        foreach ($techniques as $t) {
            $lookup[(int) $t['id']][] = $t['external_id'];
            if ($t['parent_id']) {
                $lookup[(int) $t['parent_id']][] = $t['external_id'];
            }
        }
        $pairs = (new Query())->select(['mitigation_id', 'technique_id'])->from('attack_mitigation_technique')
            ->where(['technique_id' => array_keys($lookup)])->all();
        $covered = [];
        foreach ($pairs as $p) {
            foreach ($lookup[(int) $p['technique_id']] as $externalId) {
                $covered[(int) $p['mitigation_id']][$externalId] = true;
            }
        }
        if (!$covered) {
            return [];
        }
        $mitigations = AttackMitigation::find()->where(['id' => array_keys($covered), 'is_deprecated' => 0])
            ->andWhere(['not in', 'external_id', self::NOT_ACTIONABLE])->indexBy('id')->all();
        $adoption ??= self::adoption();
        $rows = [];
        foreach ($mitigations as $id => $m) {
            $ids = array_keys($covered[$id]);
            natsort($ids);
            $rows[$id] = [
                'mitigation' => $m,
                'techniques' => array_values($ids),
                'state' => $adoption[$id]['state'] ?? self::STATE_GAP,
                'controls' => $adoption[$id]['controls'] ?? [],
                'decisions' => $adoption[$id]['decisions'] ?? [],
            ];
        }
        $order = [self::STATE_GAP => 0, self::STATE_PROPOSED => 1, self::STATE_APPROVED => 2, self::STATE_IMPLEMENTED => 3];
        uasort($rows, static fn($a, $b) => [$order[$a['state']], -count($a['techniques']), $a['mitigation']->external_id]
            <=> [$order[$b['state']], -count($b['techniques']), $b['mitigation']->external_id]);
        return $rows;
    }

    /** Summary for a mitigationsFor() result: total / addressed (planned or in place). */
    public static function summarise(array $rows): array
    {
        $addressed = count(array_filter($rows, static fn($r) => in_array($r['state'], [self::STATE_IMPLEMENTED, self::STATE_APPROVED], true)));
        return ['total' => count($rows), 'addressed' => $addressed, 'gaps' => count($rows) - $addressed];
    }
}
