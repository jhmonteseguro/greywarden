<?php

namespace app\modules\threat\components;

use app\modules\attack\components\Coverage;
use app\modules\attack\models\AttackGroup;
use app\modules\attack\models\AttackTechnique;
use yii\db\Query;

/**
 * A threat group's techniques set against this organisation's defences: which mitigations
 * MITRE recommends for them and how far each is adopted (Coverage, so the answer matches
 * the rest of the ATT&CK pages), and which techniques that leaves exposed.
 */
final class ActorProfile
{
    /** MITRE lists no mitigation for the technique, so nothing can be adopted */
    public const STATE_UNMAPPED = 'unmapped';

    /** Most exposed first */
    private const STATE_ORDER = [Coverage::STATE_GAP => 0, Coverage::STATE_PROPOSED => 1, self::STATE_UNMAPPED => 2, Coverage::STATE_APPROVED => 3, Coverage::STATE_IMPLEMENTED => 4];

    /** @var AttackTechnique[] */
    public array $techniques;
    /** @var array see Coverage::mitigationsFor() */
    public array $mitigations;
    /** @var array{total:int, addressed:int, gaps:int} see Coverage::summarise() */
    public array $summary;
    /** @var array<string, string> technique ATT&CK id => best state of its mitigations */
    public array $states = [];
    /** @var array<int, int> technique id => incidents it was seen in */
    public array $incidents;
    /** @var array<int, int> technique id => open risks it is mapped to */
    public array $risks;

    public function __construct(AttackGroup $group)
    {
        $this->techniques = AttackTechnique::find()->alias('t')->with(['parent', 'tactics'])
            ->innerJoin(['u' => 'attack_group_technique'], 'u.technique_id = t.id')
            ->where(['u.group_id' => $group->id, 't.is_deprecated' => 0])->orderBy(['t.external_id' => SORT_ASC])->all();
        $this->mitigations = Coverage::mitigationsFor(array_map(static fn(AttackTechnique $t) => $t->id, $this->techniques));
        $this->summary = Coverage::summarise($this->mitigations);
        $this->incidents = Coverage::counts(Coverage::LAYER_INCIDENTS);
        $this->risks = Coverage::counts(Coverage::LAYER_RISKS);

        foreach ($this->techniques as $technique) {
            $this->states[$technique->external_id] = self::STATE_UNMAPPED;
        }
        $rank = [Coverage::STATE_GAP => 1, Coverage::STATE_PROPOSED => 2, Coverage::STATE_APPROVED => 3, Coverage::STATE_IMPLEMENTED => 4];
        foreach ($this->mitigations as $row) {
            foreach ($row['techniques'] as $id) {
                if ($this->states[$id] === self::STATE_UNMAPPED || $rank[$row['state']] > $rank[$this->states[$id]]) {
                    $this->states[$id] = $row['state'];
                }
            }
        }
    }

    /** Techniques with a mitigation planned or in place */
    public function getCoveredCount(): int
    {
        return count(array_filter($this->states, static fn(string $state) => in_array($state, [Coverage::STATE_IMPLEMENTED, Coverage::STATE_APPROVED], true)));
    }

    /**
     * Of these techniques, those with a mitigation MITRE recommends and none planned or in place
     * @param AttackTechnique[] $techniques
     */
    public function exposedCount(array $techniques): int
    {
        return count(array_filter($techniques, fn(AttackTechnique $t) => in_array($this->states[$t->external_id], [Coverage::STATE_GAP, Coverage::STATE_PROPOSED], true)));
    }

    /** Techniques also seen in this organisation's incidents or open risks */
    public function getSeenCount(): int
    {
        return count(array_filter($this->techniques, fn(AttackTechnique $t) => isset($this->incidents[$t->id]) || isset($this->risks[$t->id])));
    }

    /** @return array<array{tactic: \app\modules\attack\models\AttackTactic, techniques: AttackTechnique[]}> in matrix order, most exposed first */
    public function byTactic(): array
    {
        $tactics = [];
        foreach ($this->techniques as $technique) {
            foreach ($technique->tactics as $tactic) {
                $tactics[$tactic->id] ??= ['tactic' => $tactic, 'techniques' => []];
                $tactics[$tactic->id]['techniques'][] = $technique;
            }
        }
        uasort($tactics, static fn(array $a, array $b) => $a['tactic']->sort_order <=> $b['tactic']->sort_order);
        foreach ($tactics as &$entry) {
            usort($entry['techniques'], fn(AttackTechnique $a, AttackTechnique $b) => [self::STATE_ORDER[$this->states[$a->external_id]], $a->external_id] <=> [self::STATE_ORDER[$this->states[$b->external_id]], $b->external_id]);
        }
        unset($entry);
        return array_values($tactics);
    }

    /**
     * Mitigation summaries for a page of groups.
     * @param int[] $groupIds
     * @return array<int, array{total:int, addressed:int, gaps:int}>
     */
    public static function summaries(array $groupIds): array
    {
        if (!$groupIds) {
            return [];
        }
        $techniques = [];
        foreach ((new Query())->select(['u.group_id', 'u.technique_id'])->from(['u' => 'attack_group_technique'])
                     ->innerJoin(['t' => 'attack_technique'], 't.id = u.technique_id')
                     ->where(['u.group_id' => $groupIds, 't.is_deprecated' => 0])->all() as $row) {
            $techniques[(int) $row['group_id']][] = (int) $row['technique_id'];
        }
        $adoption = Coverage::adoption();
        $out = [];
        foreach ($techniques as $groupId => $ids) {
            $out[$groupId] = Coverage::summarise(Coverage::mitigationsFor($ids, $adoption));
        }
        return $out;
    }
}
