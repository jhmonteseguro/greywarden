<?php

namespace app\modules\risk\widgets;

use app\helpers\RiskMatrix;
use app\modules\risk\models\Risk;
use yii\base\Widget;

/**
 * 5x5 risk heatmap (likelihood on Y, impact on X) for open, non-archived risks.
 * Each cell shows the count and links to the filtered risk register.
 *
 * Markup (widgets/views/heatmap.php):
 *   div.gw-heatmap[data-mode] > table.gw-heatmap__grid > td.gw-heatmap__cell.gw-heatmap__cell--{rating}
 */
class RiskHeatmap extends Widget
{
    public const MODE_INHERENT = 'inherent';
    public const MODE_RESIDUAL = 'residual';
    public const MODE_TARGET = 'target';

    public string $mode = self::MODE_RESIDUAL;
    /** Render inherent/residual/target toggle links (uses ?{toggleParam}=...) */
    public bool $showToggle = true;
    public string $toggleParam = 'heatmap';
    public bool $compact = false;

    public function run(): string
    {
        if (!in_array($this->mode, [self::MODE_INHERENT, self::MODE_RESIDUAL, self::MODE_TARGET], true)) {
            $this->mode = self::MODE_RESIDUAL;
        }
        $l = $this->mode . '_likelihood';
        $i = $this->mode . '_impact';

        $rows = Risk::find()->active()->open()
            ->select(['l' => $l, 'i' => $i, 'n' => 'COUNT(*)'])
            ->andWhere(['not', [$l => null]])->andWhere(['not', [$i => null]])
            ->groupBy([$l, $i])->asArray()->all();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['l']][(int) $row['i']] = (int) $row['n'];
        }
        $unassessed = $this->mode === self::MODE_INHERENT
            ? 0
            : (int) Risk::find()->active()->open()->andWhere([$l => null])->count();

        return $this->render('heatmap', [
            'mode' => $this->mode,
            'counts' => $counts,
            'total' => array_sum(array_map('array_sum', $counts)),
            'unassessed' => $unassessed,
            'showToggle' => $this->showToggle,
            'toggleParam' => $this->toggleParam,
            'compact' => $this->compact,
            'likelihoodLabels' => RiskMatrix::likelihoodLabels(),
            'impactLabels' => RiskMatrix::impactLabels(),
        ]);
    }
}
