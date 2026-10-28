<?php

namespace app\modules\dashboard\widgets;

use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;

/** % of in-service cloud assets and accounts passing every cloud check that applies to them. */
class CloudComplianceKpi extends KpiWidget
{
    public string $label = 'Cloud compliance';
    public string $icon = 'cloud-check';

    protected function compute(): array
    {
        $total = (int) CloudAsset::find()->active()->inService()->count() + (int) CloudAccount::find()->active()->inService()->count();
        $compliant = (int) CloudAsset::find()->active()->inService()->compliant()->count() + (int) CloudAccount::find()->active()->inService()->compliant()->count();
        $failingAssets = (int) CloudAsset::find()->active()->inService()->nonCompliant()->count();
        $failing = $failingAssets + (int) CloudAccount::find()->active()->inService()->nonCompliant()->count();
        $pct = $total ? (int) round($compliant / $total * 100) : 0;
        return [
            'value' => $total ? $pct : '—',
            'unit' => $total ? '%' : '',
            'tone' => !$total ? 'neutral' : ($pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'critical')),
            'url' => $failing && !$failingAssets ? ['/cloud/account/index', 'filter' => 'non-compliant']
                : ['/cloud/asset/index', 'filter' => $failing ? 'non-compliant' : 'not-assessed'],
            'breakdown' => [
                ['label' => 'Compliant', 'value' => $compliant, 'tone' => 'success'],
                ['label' => 'Failing', 'value' => $failing, 'tone' => 'danger'],
                ['label' => 'Not assessed', 'value' => $total - $compliant - $failing, 'tone' => 'warning'],
            ],
        ];
    }
}
