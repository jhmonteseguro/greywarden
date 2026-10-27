<?php

namespace app\modules\dashboard\widgets;

use app\modules\incident\models\Incident;

/** Open incidents by severity. */
class OpenIncidentsKpi extends KpiWidget
{
    public string $label = 'Open incidents';
    public string $icon = 'fire';

    protected function compute(): array
    {
        $rows = Incident::find()->active()->open()->select(['severity', 'n' => 'COUNT(*)'])->groupBy('severity')->asArray()->all();
        $counts = array_column($rows, 'n', 'severity');
        $breakdown = [];
        foreach (Incident::severityOptions() as $key => $label) {
            $breakdown[] = ['label' => $label, 'value' => (int) ($counts[$key] ?? 0), 'tone' => $key];
        }
        $total = array_sum(array_map('intval', $counts));
        return [
            'value' => $total,
            'tone' => !empty($counts['critical']) ? 'critical' : ($total ? 'high' : 'success'),
            'url' => ['/incident/incident/index', 'filter' => 'open'],
            'breakdown' => $breakdown,
        ];
    }
}
