<?php

namespace app\modules\attack\widgets;

use app\helpers\Badge;
use app\modules\attack\components\Coverage;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Table of the ATT&CK mitigations MITRE recommends for a set of techniques,
 * with their adoption state from the Control Register and the Decision Log.
 *
 * Markup: div.gw-coverage > p.gw-coverage__summary + table.gw-coverage__table
 */
class MitigationCoverage extends Widget
{
    /** @var int[] technique ids */
    public array $techniqueIds = [];
    public string $emptyText = 'Map ATT&CK techniques to see which mitigations address them.';

    public function run(): string
    {
        $rows = Coverage::mitigationsFor($this->techniqueIds);
        if (!$rows) {
            return Html::tag('p', Html::encode($this->techniqueIds ? 'MITRE lists no mitigations for these techniques.' : $this->emptyText), ['class' => 'gw-empty']);
        }
        return $this->render('coverage', ['rows' => $rows, 'summary' => Coverage::summarise($rows)]);
    }

    /** Control and decision links for a coverage / adoption row */
    public static function recordLinks(array $row): string
    {
        $links = array_map(static fn($c) => Html::a(Html::encode($c->reference), $c->getViewUrl(), [
            'class' => 'gw-ref', 'title' => $c->title . ' (' . $c->getStatusLabel() . ', ' . strtolower($c->getEffectivenessLabel()) . ')',
        ]), $row['controls'] ?? []);
        if (!empty($row['decisions'])) {
            $links[] = self::decisionLinks($row['decisions']);
        }
        return $links ? implode(' ', $links) : '<span class="gw-null">&mdash;</span>';
    }

    /** Decision links for a coverage row */
    public static function decisionLinks(array $decisions): string
    {
        return implode(' ', array_map(static fn($d) => Html::a(Html::encode($d->reference), $d->getViewUrl(), [
            'class' => 'gw-ref', 'title' => $d->title . ' (' . $d->status . ')',
        ]), $decisions));
    }

    public static function stateBadge(string $state): string
    {
        return Badge::mitigationState($state);
    }
}
