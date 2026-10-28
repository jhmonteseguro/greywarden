<?php

namespace app\modules\dashboard\widgets;

use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\inventory\components\OsLifecycle;

/** Cloud assets in service (active or stopped), with the internet-facing ones. */
class CloudAssetsKpi extends KpiWidget
{
    public string $label = 'Cloud assets';
    public string $icon = 'cloud';

    protected function compute(): array
    {
        $active = (int) CloudAsset::find()->active()->inService()->count();
        $exposed = (int) CloudAsset::find()->active()->inService()->internetFacing()->count();
        $accounts = (int) CloudAccount::find()->active()->inService()->count();
        $unsupported = (int) CloudAsset::find()->active()->inService()->osSupport(OsLifecycle::UNSUPPORTED)->count();
        return [
            'value' => $active,
            'tone' => 'info',
            'url' => ['/cloud/asset/index', 'filter' => 'active'],
            'breakdown' => [
                ['label' => 'Accounts', 'value' => $accounts, 'tone' => 'neutral'],
                ['label' => 'Internet-facing', 'value' => $exposed, 'tone' => $exposed ? 'warning' : 'neutral'],
                ['label' => 'Unsupported OS', 'value' => $unsupported, 'tone' => $unsupported ? 'high' : 'neutral'],
            ],
        ];
    }
}
