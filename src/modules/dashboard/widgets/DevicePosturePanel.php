<?php

namespace app\modules\dashboard\widgets;

use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\widgets\Chart;
use yii\helpers\Html;
use yii\helpers\Url;

/** In-service devices per device check: pass / fail / not assessed (devices the check applies to). */
class DevicePosturePanel extends PanelWidget
{
    public string $title = 'Device posture';
    public string $icon = 'shield-lock';
    public string $key = 'device-posture';

    protected function renderTools(): string
    {
        return Html::a('Checks', ['/control/control/index', 'filter' => 'device-checks'], ['class' => 'gw-panel__link']);
    }

    protected function renderBody(): string
    {
        $checks = DeviceChecks::all();
        if (!$checks) {
            return Html::tag('p', 'No device checks are in force. Add one from the Control Register.', ['class' => 'gw-empty']);
        }
        $labels = $links = [];
        $data = [DevicePosture::RESULT_PASS => [], DevicePosture::RESULT_FAIL => [], DevicePosture::RESULT_UNKNOWN => []];
        foreach ($checks as $id => $check) {
            $labels[] = $check->shortLabel();
            foreach (array_keys($data) as $result) {
                $data[$result][] = (int) Device::find()->active()->inService()->checkResult($check, $result)->count();
            }
            $links[] = Url::to(['/inventory/device/index', 'filter' => 'active', 'control' => $id, 'result' => DevicePosture::RESULT_FAIL]);
        }

        return Chart::widget([
            'type' => 'bar',
            'horizontal' => true,
            'stacked' => true,
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Pass', 'tone' => 'success', 'data' => $data[DevicePosture::RESULT_PASS]],
                ['label' => 'Fail', 'tone' => 'danger', 'data' => $data[DevicePosture::RESULT_FAIL]],
                ['label' => 'Not assessed', 'tone' => 'warning', 'data' => $data[DevicePosture::RESULT_UNKNOWN]],
            ],
            'links' => $links,
            'ariaLabel' => 'Device check results across in-service devices',
            'height' => max(220, 48 * count($labels) + 80) . 'px',
        ]);
    }
}
