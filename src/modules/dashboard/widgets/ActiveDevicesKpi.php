<?php

namespace app\modules\dashboard\widgets;

use app\modules\inventory\models\Device;

/** Devices in service (status Active). */
class ActiveDevicesKpi extends KpiWidget
{
    public string $label = 'Active devices';
    public string $icon = 'pc-display';

    protected function compute(): array
    {
        $active = (int) Device::find()->active()->inService()->count();
        $total = (int) Device::find()->active()->count();
        $eol = (int) Device::find()->active()->inService()->pastEol()->count();
        return [
            'value' => $active,
            'tone' => 'info',
            'url' => ['/inventory/device/index', 'filter' => 'active'],
            'breakdown' => [
                ['label' => 'In inventory', 'value' => $total, 'tone' => 'neutral'],
                ['label' => 'Active past EOL', 'value' => $eol, 'tone' => $eol ? 'high' : 'neutral'],
            ],
        ];
    }
}
