<?php

namespace app\modules\attack\widgets;

use app\modules\attack\components\Catalogue;
use app\modules\attack\models\AttackTechnique;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Techniques grouped by ATT&CK tactic, in kill-chain (matrix) order.
 * A technique that serves several tactics appears under each of them.
 *
 * Markup: ol.gw-killchain > li.gw-killchain__phase > h4.gw-killchain__tactic + .gw-attack-tags
 */
class KillChain extends Widget
{
    /** @var AttackTechnique[] with `tactics` (and `parent`) relations */
    public array $techniques = [];
    public string $emptyText = 'No ATT&CK techniques mapped.';

    public function run(): string
    {
        if (!$this->techniques) {
            return Html::tag('p', Html::encode($this->emptyText), ['class' => 'gw-empty']);
        }
        $byTactic = [];
        foreach ($this->techniques as $t) {
            foreach ($t->tactics as $tactic) {
                $byTactic[$tactic->id][] = $t;
            }
        }
        $items = [];
        foreach (Catalogue::tactics() as $tactic) {
            if (empty($byTactic[$tactic->id])) {
                continue;
            }
            $items[] = Html::tag('li',
                Html::tag('h4',
                    Html::a(Html::encode($tactic->name), $tactic->getViewUrl(), ['class' => 'gw-killchain__name'])
                    . ' ' . Html::tag('span', Html::encode($tactic->external_id), ['class' => 'gw-killchain__id']),
                    ['class' => 'gw-killchain__tactic'])
                . TechniqueTags::widget(['items' => $byTactic[$tactic->id]]),
                ['class' => 'gw-killchain__phase']);
        }
        return Html::tag('ol', implode("\n", $items), ['class' => 'gw-killchain']);
    }
}
