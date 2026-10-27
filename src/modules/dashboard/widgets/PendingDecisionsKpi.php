<?php

namespace app\modules\dashboard\widgets;

use app\modules\decision\models\Decision;

/** Decisions with status Proposed. */
class PendingDecisionsKpi extends KpiWidget
{
    public string $label = 'Decisions awaiting approval';
    public string $icon = 'journal-check';

    protected function compute(): array
    {
        $n = (int) Decision::find()->active()->andWhere(['status' => Decision::STATUS_PROPOSED])->count();
        return [
            'value' => $n,
            'tone' => $n ? 'warning' : 'success',
            'url' => ['/decision/decision/index', 'filter' => 'proposed'],
            'caption' => 'Status: Proposed',
        ];
    }
}
