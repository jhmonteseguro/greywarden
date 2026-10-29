<?php
/**
 * @var yii\web\View $this
 * @var app\modules\incident\models\Incident $model
 * @var app\modules\incident\models\IncidentTimeline $entry
 */

use app\helpers\Badge;
use app\helpers\Dt;
use app\components\Rbac;
use app\models\Attachment;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\models\Control;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\decision\models\Decision;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Incident Log', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$state = $model->getRegulatorDeadlineState();
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::severity($model->severity) . ' '
    . Badge::status('incident-status', $model->status, Incident::statusOptions())
    . ($state === 'overdue' ? ' ' . Badge::flag('overdue', 'Regulator deadline overdue') : '')
    . ($state === 'pending' ? ' ' . Badge::flag('pending', 'Regulator notice due') : '');
$this->params['actions'] = RecordActions::widget(['model' => $model, 'pdf' => true]);
$fmt = Yii::$app->formatter;

$devices = $model->devices;
$cloudAssets = $model->cloudAssets;
$risks = $model->risks;
$decisions = $model->decisions;
$techniques = $model->techniques;
$controlsByRole = $model->getControlsByRole();
$controlCount = array_sum(array_map('count', $controlsByRole));

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Incident details</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                    ['attribute' => 'category_id', 'value' => $model->category->name ?? null],
                    ['attribute' => 'severity', 'format' => 'raw', 'value' => Badge::severity($model->severity)],
                    ['attribute' => 'status', 'format' => 'raw', 'value' => Badge::status('incident-status', $model->status, Incident::statusOptions())],
                    ['label' => 'Security impact', 'format' => 'raw', 'value' => Badge::impacts($model->getImpacts())],
                    ['attribute' => 'assigned_to_id', 'value' => $model->assignedTo->full_name ?? null],
                    'reported_by',
                    ['label' => 'ATT&CK techniques', 'format' => 'raw', 'value' => TechniqueTags::widget(['items' => $techniques, 'compact' => true, 'emptyText' => 'None mapped'])],
                ],
            ]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Description', 'text' => $model->description]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Management summary', 'text' => $model->management_summary]) ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Response</h2>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Containment actions', 'text' => $model->containment_actions]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Eradication / recovery actions', 'text' => $model->recovery_actions]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Root cause', 'text' => $model->root_cause]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Lessons learned', 'text' => $model->lessons_learned]) ?>
        </section>
    </div>
    <?php /* A live 72h deadline is shown first on narrow screens */ ?>
    <div class="col-xl-4<?= in_array($state, ['pending', 'overdue'], true) ? ' order-first order-xl-last' : '' ?>">
        <?= $this->render('_regulator', ['model' => $model]) ?>
        <section class="gw-panel gw-metrics">
            <h2 class="gw-panel__title">Timing &amp; metrics</h2>
            <dl class="gw-dl">
                <dt>Occurred</dt><dd><?= $fmt->asDatetime($model->occurred_at) ?></dd>
                <dt>Detected</dt><dd><?= $fmt->asDatetime($model->detected_at) ?></dd>
                <dt>Reported</dt><dd><?= $fmt->asDatetime($model->reported_at) ?></dd>
                <dt>Contained</dt><dd><?= $fmt->asDatetime($model->contained_at) ?></dd>
                <dt>Closed</dt><dd><?= $fmt->asDatetime($model->closed_at) ?></dd>
            </dl>
            <div class="gw-metrics__grid">
                <?php foreach (['Time to detect' => $model->getTimeToDetect(), 'Time to contain' => $model->getTimeToContain(), 'Time to close' => $model->getTimeToClose()] as $label => $seconds): ?>
                    <div class="gw-metric">
                        <span class="gw-metric__label"><?= $label ?></span>
                        <span class="gw-metric__value"><?= $seconds === null ? '—' : Html::encode(Dt::duration($seconds)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">Record</h2>
            <?= $this->render('@app/views/partials/_record-meta', ['model' => $model]) ?>
        </section>
    </div>
</div>
<?php $details = ob_get_clean();

ob_start(); ?>
<div class="row g-4">
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Affected devices', 'icon' => 'pc-display', 'models' => $devices,
        'extra' => static fn(Device $d) => Badge::criticality($d->criticality)]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Affected cloud assets', 'icon' => 'cloud', 'models' => $cloudAssets,
        'extra' => static fn(CloudAsset $a) => Badge::criticality($a->criticality)]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related risks', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related decisions', 'icon' => 'journal-check', 'models' => $decisions,
        'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?></div>
    <?php foreach (Incident::controlRoleOptions() as $role => $label): ?>
        <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Controls: ' . lcfirst($label), 'icon' => 'shield-shaded', 'models' => $controlsByRole[$role],
            'emptyText' => 'None recorded.',
            'extra' => static fn(Control $c) => Badge::effectiveness($c->effectiveness)]) ?></div>
    <?php endforeach; ?>
</div>
<?php $links = ob_get_clean(); ?>

<?php $attachments = Attachment::listFor($model); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'Timeline', 'content' => $this->render('_timeline', ['model' => $model, 'entry' => $entry, 'attachments' => $attachments]), 'options' => ['id' => 'tab-timeline']],
        ['label' => 'ATT&CK (' . count($techniques) . ')', 'content' => $this->render('@app/modules/attack/views/partials/_record-tab', [
            'techniques' => $techniques,
            'intro' => 'Adversary techniques observed in this incident.',
            'canEdit' => Rbac::canManage() && !$model->getIsArchived(),
            'editUrl' => ['update', 'id' => $model->id],
        ]), 'options' => ['id' => 'tab-attack']],
        ['label' => 'Links (' . (count($devices) + count($cloudAssets) + count($risks) + count($decisions) + $controlCount) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'incident', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
