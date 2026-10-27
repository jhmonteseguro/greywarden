<?php

namespace app\modules\dashboard\widgets;

use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\components\FrameworkCoverage;
use app\modules\control\models\Control;
use yii\helpers\Html;

/**
 * Control Register at a glance: tested effectiveness of implemented controls,
 * and ISO 27001 / Cyber Essentials progress.
 */
class ControlsPanel extends PanelWidget
{
    public string $title = 'Controls & compliance';
    public string $icon = 'shield-shaded';
    public string $key = 'controls';

    protected function renderTools(): string
    {
        return Html::a('Control Register', ['/control/control/index'], ['class' => 'gw-panel__link'])
            . Html::a('Frameworks', ['/control/framework/index'], ['class' => 'gw-panel__link']);
    }

    protected function renderBody(): string
    {
        $today = date('Y-m-d');
        $effectiveness = array_map('intval', Control::find()->active()->implemented()
            ->select(['n' => 'COUNT(*)', 'effectiveness'])->groupBy('effectiveness')->indexBy('effectiveness')->column());
        $frameworks = [];
        foreach (FrameworkCatalogue::frameworks() as $framework) {
            $frameworks[$framework] = FrameworkCoverage::summary(FrameworkCoverage::rows($framework));
        }
        return $this->render('controls', [
            'effectiveness' => $effectiveness + array_fill_keys(array_keys(Control::effectivenessOptions()), 0),
            'implemented' => array_sum($effectiveness),
            'planned' => (int) Control::find()->active()->andWhere(['status' => Control::PENDING_STATUSES])->count(),
            'testOverdue' => (int) Control::find()->active()->testOverdue($today)->count(),
            'testDueSoon' => (int) Control::find()->active()->testDueSoon($today)->count(),
            'frameworks' => $frameworks,
        ]);
    }
}
