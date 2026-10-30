<?php
/**
 * PDF of a management report (components/RecordPdf): the same body as the
 * report page, from the frozen figures.
 * @var yii\web\View $this
 * @var app\modules\report\models\Report $model
 */

use app\helpers\Badge;
use app\modules\report\models\Report;
use yii\helpers\Html;

$this->params['pdf.register'] = 'Management report · ' . $model->getPeriodName();
$this->params['pdf.badges'] = Badge::status('report-status', $model->status, Report::statusOptions())
    . ($model->rag ? ' ' . Badge::render('rag', $model->rag, $model->getRagLabel()) : '');
?>
<?php if (!$model->isApproved()): ?>
    <div class="notice notice--interim"><strong>Draft</strong> &nbsp;This report has not been approved yet; the summary and overall status may still change.</div>
<?php endif; ?>
<?php if ($model->hasReadableFigures()): ?>
    <?= $this->render('_body', ['model' => $model, 'pdf' => true]) ?>
<?php else: ?>
    <p class="null">The figures of this report were made by another version of Greywarden and cannot be shown. Regenerate them while the report is a draft.</p>
<?php endif; ?>
