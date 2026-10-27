<?php

namespace app\modules\dashboard\widgets;

use app\modules\incident\models\Incident;

/** Incidents past their regulator notification deadline without notification. */
class RegulatorOverdueKpi extends KpiWidget
{
    public string $label = 'Regulator deadline overdue';
    public string $icon = 'hourglass-bottom';

    protected function compute(): array
    {
        $overdue = (int) Incident::find()->active()->regulatorOverdue()->count();
        $pending = (int) Incident::find()->active()->regulatorPending()->count() - $overdue;
        return [
            'value' => $overdue,
            'tone' => $overdue ? 'critical' : ($pending ? 'warning' : 'success'),
            'url' => ['/incident/incident/index', 'filter' => $overdue ? 'regulator-overdue' : 'regulator-pending'],
            'caption' => sprintf('%d awaiting notification within %dh', $pending, Incident::deadlineHours()),
        ];
    }
}
