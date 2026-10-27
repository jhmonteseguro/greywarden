<?php

namespace app\modules\dashboard\widgets;

use app\modules\inventory\models\Device;

/** % of in-service devices passing every device check that applies to them. */
class DeviceComplianceKpi extends KpiWidget
{
    public string $label = 'Device compliance';
    public string $icon = 'shield-check';

    protected function compute(): array
    {
        $active = (int) Device::find()->active()->inService()->count();
        $compliant = (int) Device::find()->active()->inService()->compliant()->count();
        $failing = (int) Device::find()->active()->inService()->nonCompliant()->count();
        $pct = $active ? (int) round($compliant / $active * 100) : 0;
        return [
            'value' => $active ? $pct : '—',
            'unit' => $active ? '%' : '',
            'tone' => !$active ? 'neutral' : ($pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'critical')),
            'url' => ['/inventory/device/index', 'filter' => $failing ? 'non-compliant' : 'not-assessed'],
            'breakdown' => [
                ['label' => 'Compliant', 'value' => $compliant, 'tone' => 'success'],
                ['label' => 'Failing', 'value' => $failing, 'tone' => 'danger'],
                ['label' => 'Not assessed', 'value' => $active - $compliant - $failing, 'tone' => 'warning'],
            ],
        ];
    }
}
