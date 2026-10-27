<?php

namespace app\modules\dashboard\widgets;

use app\modules\attack\components\Catalogue;
use app\modules\attack\components\Coverage;
use app\modules\attack\models\AttackTechnique;
use app\widgets\Chart;
use yii\helpers\Html;
use yii\helpers\Url;

/**
 * MITRE ATT&CK over the last N months: incidents per tactic (kill-chain order)
 * and the most frequently observed techniques with their mitigation coverage.
 */
class AttackPanel extends PanelWidget
{
    public string $title = 'ATT&CK: observed adversary behaviour';
    public string $icon = 'crosshair';
    public string $key = 'attack';
    public int $months = 12;
    public int $top = 6;

    protected function renderTools(): string
    {
        return Html::a('Matrix', ['/attack/technique/index'], ['class' => 'gw-panel__link'])
            . Html::a('Mitigations', ['/attack/mitigation/index'], ['class' => 'gw-panel__link']);
    }

    protected function renderBody(): string
    {
        if (!Catalogue::isLoaded()) {
            return Html::tag('p', 'The ATT&CK catalogue is not loaded. Run php yii attack/import.', ['class' => 'gw-empty']);
        }
        $since = date('Y-m-d 00:00:00', strtotime("-{$this->months} months"));

        $perTactic = Coverage::incidentsPerTactic($since);
        $labels = $data = $links = [];
        foreach (Catalogue::tactics() as $tactic) {
            $labels[] = $tactic->name;
            $data[] = $perTactic[$tactic->id] ?? 0;
            $links[] = Url::to(['/attack/technique/index', 'show' => 'mapped', '#' => $tactic->external_id]);
        }
        $chart = array_sum($data) ? Chart::widget([
            'type' => 'bar',
            'horizontal' => true,
            'labels' => $labels,
            'datasets' => [['label' => 'Incidents', 'tone' => 'high', 'data' => $data]],
            'links' => $links,
            'ariaLabel' => sprintf('Incidents per ATT&CK tactic over the last %d months', $this->months),
            'height' => '340px',
        ]) : Html::tag('p', 'No ATT&CK techniques recorded on incidents in this period.', ['class' => 'gw-empty']);

        $counts = Coverage::counts(Coverage::LAYER_INCIDENTS, false, $since);
        arsort($counts);
        $top = array_slice($counts, 0, $this->top, true);
        $techniques = $top ? AttackTechnique::find()->with('parent')->where(['id' => array_keys($top)])->indexBy('id')->all() : [];
        $adoption = Coverage::adoption();
        $rows = [];
        foreach ($top as $id => $n) {
            if (!isset($techniques[$id])) {
                continue;
            }
            $rows[] = [
                'technique' => $techniques[$id],
                'incidents' => $n,
                'coverage' => Coverage::summarise(Coverage::mitigationsFor([$id], $adoption)),
            ];
        }

        return $this->render('attack', [
            'chart' => $chart,
            'rows' => $rows,
            'months' => $this->months,
            'incidentUrl' => static fn(AttackTechnique $t) => Url::to(['/incident/incident/index', 'filter' => 'all', 'technique' => $t->external_id]),
            'label' => Catalogue::label(),
        ]);
    }
}
