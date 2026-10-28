<?php

namespace app\modules\dashboard\widgets;

use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\components\CloudPosture;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\widgets\Chart;
use yii\helpers\Html;
use yii\helpers\Url;

/** In-service cloud assets and accounts per cloud check: pass / fail / not assessed (those the check applies to). */
class CloudPosturePanel extends PanelWidget
{
    public string $title = 'Cloud posture';
    public string $icon = 'cloud-check';
    public string $key = 'cloud-posture';

    protected function renderTools(): string
    {
        return Html::a('Checks', ['/control/control/index', 'filter' => 'cloud-checks'], ['class' => 'gw-panel__link']);
    }

    protected function renderBody(): string
    {
        $checks = CloudChecks::all();
        if (!$checks) {
            return Html::tag('p', 'No cloud checks are in force. Add one from the Control Register.', ['class' => 'gw-empty']);
        }
        $labels = $links = [];
        $data = [CloudPosture::RESULT_PASS => [], CloudPosture::RESULT_FAIL => [], CloudPosture::RESULT_UNKNOWN => []];
        foreach ($checks as $id => $check) {
            $labels[] = $check->shortLabel();
            $assets = $check->assetTypes() !== [];
            foreach (array_keys($data) as $result) {
                $data[$result][] = ($assets ? (int) CloudAsset::find()->active()->inService()->checkResult($check, $result)->count() : 0)
                    + ($check->appliesToAccounts() ? (int) CloudAccount::find()->active()->inService()->checkResult($check, $result)->count() : 0);
            }
            $links[] = Url::to([$assets ? '/cloud/asset/index' : '/cloud/account/index', 'filter' => 'active', 'control' => $id, 'result' => CloudPosture::RESULT_FAIL]);
        }

        return Chart::widget([
            'type' => 'bar',
            'horizontal' => true,
            'stacked' => true,
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Pass', 'tone' => 'success', 'data' => $data[CloudPosture::RESULT_PASS]],
                ['label' => 'Fail', 'tone' => 'danger', 'data' => $data[CloudPosture::RESULT_FAIL]],
                ['label' => 'Not assessed', 'tone' => 'warning', 'data' => $data[CloudPosture::RESULT_UNKNOWN]],
            ],
            'links' => $links,
            'ariaLabel' => 'Cloud check results across in-service cloud assets and accounts',
            'height' => max(220, 48 * count($labels) + 80) . 'px',
        ]);
    }
}
