<?php

namespace app\modules\dashboard\widgets;

use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;

/** Overdue risk reviews + decision reviews. */
class OverdueReviewsKpi extends KpiWidget
{
    public string $label = 'Overdue reviews';
    public string $icon = 'calendar-x';

    protected function compute(): array
    {
        $today = date('Y-m-d');
        $risks = (int) Risk::find()->active()->reviewOverdue($today)->count();
        $decisions = (int) Decision::find()->active()
            ->andWhere(['status' => Decision::REVIEWABLE_STATUSES])
            ->andWhere(['<', 'review_date', $today])->count();
        return [
            'value' => $risks + $decisions,
            'tone' => ($risks + $decisions) ? 'warning' : 'success',
            'url' => ['/risk/risk/index', 'filter' => 'review-overdue'],
            'breakdown' => [
                ['label' => 'Risks', 'value' => $risks, 'tone' => $risks ? 'warning' : 'neutral'],
                ['label' => 'Decisions', 'value' => $decisions, 'tone' => $decisions ? 'warning' : 'neutral'],
            ],
        ];
    }
}
