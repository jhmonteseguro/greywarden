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
        $risksUrl = ['/risk/risk/index', 'filter' => 'review-overdue'];
        $decisionsUrl = ['/decision/decision/index', 'filter' => 'review-overdue'];
        return [
            'value' => $risks + $decisions,
            'tone' => ($risks + $decisions) ? 'warning' : 'success',
            // The tile links to whichever list has overdue reviews; when both do, each breakdown row links instead
            'url' => match (true) {
                $risks && $decisions => null,
                $decisions > 0 => $decisionsUrl,
                default => $risksUrl,
            },
            'breakdown' => [
                ['label' => 'Risks', 'value' => $risks, 'tone' => $risks ? 'warning' : 'neutral',
                    'url' => $risks && $decisions ? $risksUrl : null],
                ['label' => 'Decisions', 'value' => $decisions, 'tone' => $decisions ? 'warning' : 'neutral',
                    'url' => $risks && $decisions ? $decisionsUrl : null],
            ],
        ];
    }
}
