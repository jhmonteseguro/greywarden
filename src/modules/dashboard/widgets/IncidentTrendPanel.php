<?php

namespace app\modules\dashboard\widgets;

use app\modules\incident\models\Incident;
use app\widgets\Chart;
use yii\db\Expression;
use yii\helpers\Url;

/** Incidents detected per month (last 12 months), stacked by severity. */
class IncidentTrendPanel extends PanelWidget
{
    public string $title = 'Incidents per month';
    public string $icon = 'bar-chart';
    public string $key = 'incident-trend';
    public int $months = 12;

    protected function renderBody(): string
    {
        $start = new \DateTimeImmutable('first day of this month 00:00');
        $start = $start->modify('-' . ($this->months - 1) . ' months');

        $rows = Incident::find()->active()
            ->select(['ym' => new Expression("DATE_FORMAT(detected_at, '%Y-%m')"), 'severity', 'n' => 'COUNT(*)'])
            ->andWhere(['>=', 'detected_at', $start->format('Y-m-d H:i:s')])
            ->groupBy(['ym', 'severity'])->asArray()->all();

        $months = [];
        for ($i = 0; $i < $this->months; $i++) {
            $months[] = $start->modify("+{$i} months");
        }
        $data = [];
        foreach ($rows as $row) {
            $data[$row['severity']][$row['ym']] = (int) $row['n'];
        }

        $datasets = [];
        foreach (array_reverse(Incident::severityOptions(), true) as $sev => $label) {
            $datasets[] = [
                'label' => $label,
                'tone' => $sev,
                'data' => array_map(static fn(\DateTimeImmutable $m) => $data[$sev][$m->format('Y-m')] ?? 0, $months),
            ];
        }

        return Chart::widget([
            'type' => 'bar',
            'stacked' => true,
            'labels' => array_map(static fn(\DateTimeImmutable $m) => $m->format('M y'), $months),
            'datasets' => $datasets,
            'links' => array_map(static fn(\DateTimeImmutable $m) => Url::to(['/incident/incident/index', 'filter' => 'all', 'month' => $m->format('Y-m')]), $months),
            'ariaLabel' => 'Incidents detected per month by severity',
            'height' => '300px',
        ]);
    }
}
