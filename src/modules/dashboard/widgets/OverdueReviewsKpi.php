<?php

namespace app\modules\dashboard\widgets;

use app\modules\decision\models\Decision;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;

/** Overdue risk, decision and supplier reviews. */
class OverdueReviewsKpi extends KpiWidget
{
    public string $label = 'Overdue reviews';
    public string $icon = 'calendar-x';

    protected function compute(): array
    {
        $today = date('Y-m-d');
        $rows = [
            'Risks' => [(int) Risk::find()->active()->reviewOverdue($today)->count(), ['/risk/risk/index', 'filter' => 'review-overdue']],
            'Decisions' => [(int) Decision::find()->active()
                ->andWhere(['status' => Decision::REVIEWABLE_STATUSES])
                ->andWhere(['<', 'review_date', $today])->count(), ['/decision/decision/index', 'filter' => 'review-overdue']],
            'Suppliers' => [(int) Supplier::find()->active()->reviewOverdue($today)->count(), ['/supplier/supplier/index', 'filter' => 'review-overdue']],
        ];
        $total = array_sum(array_column($rows, 0));
        $nonZero = array_filter($rows, static fn($r) => $r[0] > 0);
        // The tile links to the one list with overdue reviews; when several have some, each breakdown row links instead
        $several = count($nonZero) > 1;
        return [
            'value' => $total,
            'tone' => $total ? 'warning' : 'success',
            'url' => $several ? null : (reset($nonZero) ?: $rows['Risks'])[1],
            'breakdown' => array_map(static fn($label, $r) => [
                'label' => $label, 'value' => $r[0], 'tone' => $r[0] ? 'warning' : 'neutral', 'url' => $several && $r[0] ? $r[1] : null,
            ], array_keys($rows), $rows),
        ];
    }
}
