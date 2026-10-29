<?php
/**
 * PDF body for a decision (components/RecordPdf).
 * @var yii\web\View $this
 * @var app\modules\decision\models\Decision $model
 */

use app\helpers\Badge;
use app\models\Attachment;
use app\models\RecordNote;
use app\models\RecordReview;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\risk\models\Risk;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$reviews = RecordReview::listFor($model);
$this->params['pdf.register'] = 'Decision Log';
$this->params['pdf.badges'] = Badge::status('decision-status', $model->status, Decision::statusOptions())
    . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Review overdue') : '');
?>
<h2>Summary</h2>
<?= $this->render('@app/views/pdf/_facts', ['facts' => [
    'Decision date' => $fmt->asDate($model->decision_date),
    'Category' => Html::encode($model->category->name ?? ''),
    'Decision makers' => Html::encode((string) $model->decision_makers),
    'Stakeholders' => nl2br(Html::encode((string) $model->stakeholders)),
    'Review date' => $model->review_date ? $fmt->asDate($model->review_date) . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') : null,
    'Last reviewed' => ($last = $reviews[0] ?? null) ? $fmt->asDate($last->reviewed_on) . ' by ' . Html::encode($last->reviewedBy->full_name ?? '—') : 'Never',
    'Superseded by' => $model->supersededBy ? '<span class="ref">' . Html::encode($model->supersededBy->reference) . '</span> ' . Html::encode($model->supersededBy->title) : null,
]]) ?>

<h2>Decision record</h2>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Context / problem statement', 'text' => $model->context]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Options considered', 'text' => $model->options_considered]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Decision', 'text' => $model->decision_text, 'highlight' => true]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Rationale', 'text' => $model->rationale]) ?>
<?= $this->render('@app/views/pdf/_text', ['label' => 'Impact / consequences', 'text' => $model->impact]) ?>

<h2>Related records</h2>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Controls', 'models' => $model->controls, 'extraLabel' => 'Status',
    'emptyText' => 'This decision does not introduce or change a control.',
    'extra' => static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions()) . ' ' . Badge::effectiveness($c->effectiveness)]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related risks', 'models' => $model->risks, 'extraLabel' => 'Current score',
    'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore())]) ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'Related incidents', 'models' => $model->incidents, 'extraLabel' => 'Severity',
    'extra' => static fn(Incident $i) => Badge::severity($i->severity)]) ?>
<?php if ($model->cloudAssets): ?>
    <?= $this->render('@app/views/pdf/_records', ['title' => 'Related cloud assets', 'models' => $model->cloudAssets]) ?>
<?php endif; ?>
<?php if ($model->supersedes): ?>
    <?= $this->render('@app/views/pdf/_records', ['title' => 'Supersedes', 'models' => $model->supersedes]) ?>
<?php endif; ?>
<?= $this->render('@app/views/pdf/_records', ['title' => 'ATT&CK mitigations', 'models' => $model->mitigations, 'emptyText' => 'None recorded.']) ?>

<?= $this->render('@app/views/pdf/_reviews', ['reviews' => $reviews]) ?>

<?= $this->render('@app/views/pdf/_notes', ['notes' => RecordNote::listFor($model, true)]) ?>

<?= $this->render('@app/views/pdf/_attachments', ['attachments' => Attachment::listFor($model)]) ?>
