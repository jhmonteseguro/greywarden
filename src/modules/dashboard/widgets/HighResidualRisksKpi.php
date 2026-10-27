<?php

namespace app\modules\dashboard\widgets;

use app\helpers\RiskMatrix;
use app\modules\risk\models\Risk;

/** Open risks whose residual rating is High or Critical. */
class HighResidualRisksKpi extends KpiWidget
{
    public string $label = 'Critical / high residual';
    public string $icon = 'radioactive';

    protected function compute(): array
    {
        [$highMin] = RiskMatrix::scoreRange('high');
        [$critMin] = RiskMatrix::scoreRange('critical');
        $base = Risk::find()->active()->open();
        $critical = (int) (clone $base)->andWhere(['>=', 'residual_score', $critMin])->count();
        $high = (int) (clone $base)->andWhere(['>=', 'residual_score', $highMin])->andWhere(['<', 'residual_score', $critMin])->count();
        return [
            'value' => $critical + $high,
            'tone' => $critical ? 'critical' : ($high ? 'high' : 'success'),
            'url' => ['/risk/risk/index', 'filter' => 'open', 'sort' => '-residual_rating'],
            'breakdown' => [
                ['label' => 'Critical', 'value' => $critical, 'tone' => 'critical'],
                ['label' => 'High', 'value' => $high, 'tone' => 'high'],
            ],
        ];
    }
}
