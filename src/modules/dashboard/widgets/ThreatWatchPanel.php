<?php

namespace app\modules\dashboard\widgets;

use app\helpers\Badge;
use app\modules\threat\models\ThreatTriage;
use Yii;
use yii\helpers\Html;

/** The most urgent stories still to triage: being exploited, or relevant to what you run. */
class ThreatWatchPanel extends PanelWidget
{
    public const LIMIT = 5;

    public string $title = 'Threat Watch';
    public string $icon = 'newspaper';
    public string $key = 'threat-watch';

    protected function renderTools(): string
    {
        return Html::a('View all', ['/threat/story/index', 'filter' => 'urgent'], ['class' => 'gw-panel__link']);
    }

    protected function renderBody(): string
    {
        $query = ThreatTriage::find()->forOrganisation()->open()->andWhere(['threat_triage.priority' => ['critical', 'high']]);
        $total = (int) (clone $query)->count();
        if ($total === 0) {
            return '<div class="gw-empty-state gw-empty-state--ok"><i class="bi bi-check2-circle" aria-hidden="true"></i><p>No critical or high stories to triage.</p></div>';
        }
        $fmt = Yii::$app->formatter;
        $rows = '';
        foreach ($query->urgentFirst()->limit(self::LIMIT)->all() as $triage) {
            $rows .= Html::tag('li',
                Badge::render('threat-priority', $triage->priority, ucfirst($triage->priority))
                . ' ' . Html::a(Html::encode($triage->story->title), $triage->getViewUrl(), ['class' => 'gw-threat-brief__title'])
                . Html::tag('span', Html::encode($fmt->asRelativeTime($triage->story->last_seen_at)), ['class' => 'gw-muted gw-threat-brief__when']),
                ['class' => 'gw-threat-brief__item']);
        }
        $more = $total > self::LIMIT ? Html::tag('p', sprintf('%d more critical or high.', $total - self::LIMIT), ['class' => 'gw-muted gw-threat-brief__more']) : '';
        return Html::tag('ul', $rows, ['class' => 'gw-threat-brief']) . $more;
    }
}
