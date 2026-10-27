<?php

namespace app\modules\dashboard\widgets;

use app\helpers\RiskMatrix;
use app\modules\risk\models\Risk;

/** Open risks, split by current rating (residual if assessed, else inherent). */
class OpenRisksKpi extends KpiWidget
{
    public string $label = 'Open risks';
    public string $icon = 'exclamation-triangle';

    protected function compute(): array
    {
        $scores = Risk::find()->active()->open()->select(['score' => 'COALESCE(residual_score, inherent_score)'])->column();
        $counts = array_fill_keys(array_keys(RiskMatrix::ratingOptions()), 0);
        foreach ($scores as $score) {
            $counts[RiskMatrix::rating((int) $score)]++;
        }
        $breakdown = [];
        foreach (array_reverse(RiskMatrix::ratingOptions(), true) as $key => $label) {
            $breakdown[] = ['label' => $label, 'value' => $counts[$key], 'tone' => $key];
        }
        return [
            'value' => count($scores),
            'tone' => 'info',
            'url' => ['/risk/risk/index', 'filter' => 'open'],
            'breakdown' => $breakdown,
        ];
    }
}
