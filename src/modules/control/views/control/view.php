<?php
/**
 * @var yii\web\View $this
 * @var app\modules\control\models\Control $model
 * @var app\modules\control\models\ControlTest $test form model for "Record a test"
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Attachment;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\cloud\components\CloudChecks;
use app\modules\cloud\models\CloudAccount;
use app\modules\cloud\models\CloudAsset;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DeviceChecks;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\RecordActions;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Control Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$flags = [];
if ($testFlag = $model->testFlag()) {
    $flags[] = $testFlag;
}
if ($model->isTargetOverdue()) {
    $flags[] = Badge::flag('overdue', 'Past target date');
}
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('control-status', $model->status, Control::statusOptions()) . ' '
    . Badge::effectiveness($model->effectiveness) . ' ' . implode(' ', $flags);
$this->params['actions'] = RecordActions::widget(['model' => $model, 'pdf' => true]);
$fmt = Yii::$app->formatter;
$canEdit = Rbac::canManage() && !$model->getIsArchived();

$tests = $model->tests;
$attachments = Attachment::listFor($model);
$testFiles = [];
foreach ($attachments as $a) {
    if ($a->control_test_id) {
        $testFiles[$a->control_test_id][] = $a;
    }
}
$risks = $model->risks;
$decisions = $model->decisions;
$incidents = $model->getIncidentsByRole();
$mitigations = $model->mitigations;
$requirements = [];
foreach (FrameworkCatalogue::enabled() as $framework) {
    $requirements[$framework] = $model->requirementsOf($framework);
}
$requirementCount = array_sum(array_map('count', $requirements));
// One column per framework, plus ATT&CK
$frameworkCol = match (true) { count($requirements) < 3 => 'col-lg-4', count($requirements) === 3 => 'col-lg-6 col-xxl-3', default => 'col-md-6 col-xl-4' };
$incidentCount = array_sum(array_map('count', $incidents));

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Control</h2>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Description', 'text' => $model->description]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Implementation', 'text' => $model->implementation]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Evidence', 'text' => $model->evidence]) ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Summary</h2>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                    ['attribute' => 'owner_id', 'value' => $model->owner->full_name ?? null],
                    ['attribute' => 'control_type', 'value' => $model->getTypeLabel()],
                    ['attribute' => 'nature', 'value' => $model->getNatureLabel()],
                    ['attribute' => 'status', 'format' => 'raw', 'value' => Badge::status('control-status', $model->status, Control::statusOptions())],
                    ['attribute' => 'target_date', 'format' => 'raw', 'visible' => (bool) $model->target_date,
                        'value' => $fmt->asDate($model->target_date) . ($model->isTargetOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '')],
                    ['attribute' => 'implemented_on', 'format' => 'date', 'visible' => (bool) $model->implemented_on],
                    ['label' => 'Device check', 'format' => 'raw', 'visible' => (bool) $model->device_check,
                        'value' => Html::encode($model->getDeviceScopeLabel())],
                    ['label' => 'Cloud check', 'format' => 'raw', 'visible' => (bool) $model->cloud_check,
                        'value' => Html::encode($model->getCloudScopeLabel())],
                ],
            ]) ?>
        </section>
        <section class="gw-panel gw-control-testing gw-control-testing--<?= Html::encode($model->effectiveness) ?>">
            <h2 class="gw-panel__title"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Effectiveness</h2>
            <div class="gw-control-testing__status"><?= Badge::effectiveness($model->effectiveness) ?></div>
            <?= DetailView::widget([
                'model' => $model,
                'attributes' => [
                    ['attribute' => 'test_frequency_months', 'value' => $model->getFrequencyLabel() ?? 'No schedule'],
                    ['attribute' => 'last_tested_on', 'format' => 'date'],
                    ['attribute' => 'next_test_due', 'format' => 'raw', 'visible' => (bool) $model->next_test_due,
                        'value' => $fmt->asDate($model->next_test_due) . ' ' . $model->testFlag()],
                ],
            ]) ?>
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
    <div class="col-xl-<?= $canEdit ? 8 : 12 ?>">
        <section class="gw-panel gw-panel--flush">
            <h2 class="gw-panel__title">Test history <span class="gw-panel__count"><?= count($tests) ?></span></h2>
            <?php if (!$tests): ?>
                <p class="gw-empty">No tests recorded yet. Effectiveness stays "Not tested" until the first one.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table gw-coverage__table gw-control-tests">
                        <thead><tr><th scope="col">Tested on</th><th scope="col">Result</th><th scope="col">Method &amp; findings</th><th scope="col">Tested by</th><?= $canEdit ? '<th scope="col"><span class="visually-hidden">Actions</span></th>' : '' ?></tr></thead>
                        <tbody>
                        <?php foreach ($tests as $t): ?>
                            <tr>
                                <td class="gw-mono"><?= $fmt->asDate($t->tested_on) ?></td>
                                <td><?= Badge::effectiveness($t->result) ?></td>
                                <td>
                                    <?= $t->method ? '<strong>' . Html::encode($t->method) . '</strong>' : '' ?>
                                    <?= $t->notes ? '<div class="gw-control-tests__notes">' . nl2br(Html::encode($t->notes)) . '</div>' : '' ?>
                                    <?php if ($t->evidence): ?>
                                        <div class="gw-muted gw-small"><i class="bi bi-paperclip" aria-hidden="true"></i>
                                            <?= preg_match('#^https?://#i', $t->evidence)
                                                ? Html::a(Html::encode($t->evidence), $t->evidence, ['target' => '_blank', 'rel' => 'noopener noreferrer'])
                                                : Html::encode($t->evidence) ?></div>
                                    <?php endif; ?>
                                    <?php foreach ($testFiles[$t->id] ?? [] as $a): ?>
                                        <div class="gw-small"><i class="bi bi-<?= $a->getIcon() ?> gw-muted" aria-hidden="true"></i>
                                            <?= Html::a(Html::encode($a->original_name), ['/attachment/download', 'id' => $a->id], ['title' => 'Download']) ?></div>
                                    <?php endforeach; ?>
                                </td>
                                <td><?= Html::encode($t->testedBy->full_name ?? '—') ?></td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end"><?= Html::a('<i class="bi bi-trash" aria-hidden="true"></i>', ['delete-test', 'id' => $model->id, 'test' => $t->id], [
                                        'class' => 'gw-icon-btn', 'title' => 'Remove this test', 'aria-label' => 'Remove this test',
                                        'data-method' => 'post', 'data-confirm' => 'Remove this test? The control\'s effectiveness is recalculated from the remaining tests.',
                                    ]) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php if ($canEdit): ?>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-plus-lg" aria-hidden="true"></i> Record a test</h2>
            <?php $form = \app\widgets\ActiveForm::begin(['id' => 'control-test-form', 'action' => ['record-test', 'id' => $model->id], 'options' => ['enctype' => 'multipart/form-data']]); ?>
            <?= $form->errorSummary($test, ['class' => 'alert alert-danger gw-error-summary']) ?>
            <div class="row">
                <div class="col-sm-6"><?= $form->field($test, 'tested_on')->input('date', ['max' => date('Y-m-d')]) ?></div>
                <div class="col-sm-6"><?= $form->field($test, 'result')->dropDownList(\app\modules\control\models\ControlTest::resultOptions(), ['prompt' => '— Select —']) ?></div>
            </div>
            <?= $form->field($test, 'tested_by_id')->dropDownList(\app\models\User::options($test->tested_by_id)) ?>
            <?= $form->field($test, 'method')->textInput(['maxlength' => true]) ?>
            <?= $form->field($test, 'notes')->textarea(['rows' => 3]) ?>
            <?= $form->field($test, 'evidence')->textInput(['maxlength' => true]) ?>
            <?= $form->field($test, 'evidenceFiles[]')->fileInput(['multiple' => true, 'accept' => Attachment::acceptAttribute()])
                ->label('Evidence files')
                ->hint(Html::encode(Attachment::extensionList()) . '; up to ' . (int) Yii::$app->params['attachments.maxSizeMb'] . ' MB per upload. They also appear on the Files tab.') ?>
            <?= Html::submitButton('Record test', ['class' => 'btn btn-primary gw-btn']) ?>
            <?php \app\widgets\ActiveForm::end(); ?>
        </section>
    </div>
    <?php endif; ?>
</div>
<?php $testing = ob_get_clean();

$deviceTab = null;
if ($model->device_check) {
    $check = DeviceChecks::fromControl($model);
    $counts = [];
    foreach (DevicePosture::resultLabels() as $result => $label) {
        $counts[$result] = (int) Device::find()->active()->inService()->checkResult($check, $result)->count();
    }
    ob_start(); ?>
    <section class="gw-panel">
        <h2 class="gw-panel__title"><i class="bi bi-pc-display" aria-hidden="true"></i> Checked on devices</h2>
        <?php if (!$model->isInForce()): ?>
            <div class="alert alert-info gw-callout" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i>
                This check is not in force: devices are only checked while the control is implementing or implemented.</div>
        <?php endif; ?>
        <dl class="gw-dl">
            <dt>Method</dt><dd><?= Html::encode(Control::deviceCheckOptions()[$model->device_check] ?? $model->device_check)
                . ($check->isPatchAge() ? Html::encode(sprintf(', at most %d days', $check->maxAgeDays)) : '') ?></dd>
            <dt>Applies to</dt><dd><?= Html::encode($model->getDeviceScopeLabel()) ?></dd>
        </dl>
        <ul class="gw-check-counts">
            <?php foreach ($counts as $result => $n): ?>
                <li class="gw-check-counts__item">
                    <?= Badge::checkResult($result) ?>
                    <?= Html::a('<strong>' . $n . '</strong> in-service device' . ($n === 1 ? '' : 's'),
                        ['/inventory/device/index', 'filter' => 'active', 'control' => $model->id, 'result' => $result], ['class' => 'gw-check-counts__link']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="gw-muted gw-small mb-0"><?= match (true) {
            $check->isPatchAge() => 'Measured from each device\'s "Last patched" date; devices without one are not assessed.',
            $check->isOsSupport() => 'Fails once the device\'s operating system is past its vendor end of support; devices whose OS or version has no known date are not assessed.',
            default => 'Record the result on each device\'s edit form, under Device checks.',
        } ?></p>
    </section>
    <?php $deviceTab = ob_get_clean();
}

$cloudTab = null;
if ($model->cloud_check) {
    $check = CloudChecks::fromControl($model);
    $assetCounts = $accountCounts = [];
    foreach (DevicePosture::resultLabels() as $result => $label) {
        if ($check->assetTypes() !== []) {
            $assetCounts[$result] = (int) CloudAsset::find()->active()->inService()->checkResult($check, $result)->count();
        }
        if ($check->appliesToAccounts()) {
            $accountCounts[$result] = (int) CloudAccount::find()->active()->inService()->checkResult($check, $result)->count();
        }
    }
    ob_start(); ?>
    <section class="gw-panel">
        <h2 class="gw-panel__title"><i class="bi bi-cloud" aria-hidden="true"></i> Checked on cloud assets</h2>
        <?php if (!$model->isInForce()): ?>
            <div class="alert alert-info gw-callout" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i>
                This check is not in force: cloud assets are only checked while the control is implementing or implemented.</div>
        <?php endif; ?>
        <dl class="gw-dl">
            <dt>Method</dt><dd><?= Html::encode(Control::cloudCheckOptions()[$model->cloud_check] ?? $model->cloud_check) ?></dd>
            <dt>Applies to</dt><dd><?= Html::encode($model->getCloudScopeLabel()) ?></dd>
        </dl>
        <?php foreach (['asset' => [$assetCounts, '/cloud/asset/index', 'in-service asset'], 'account' => [$accountCounts, '/cloud/account/index', 'cloud account']] as $kind => [$counts, $route, $noun]): if (!$counts) continue; ?>
            <ul class="gw-check-counts gw-check-counts--<?= $kind ?>">
                <?php foreach ($counts as $result => $n): ?>
                    <li class="gw-check-counts__item">
                        <?= Badge::checkResult($result) ?>
                        <?= Html::a('<strong>' . $n . '</strong> ' . $noun . ($n === 1 ? '' : 's'),
                            [$route, 'filter' => 'active', 'control' => $model->id, 'result' => $result], ['class' => 'gw-check-counts__link']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <p class="gw-muted gw-small mb-0"><?= match (true) {
            $check->isOsSupport() => 'Fails once an instance\'s operating system is past its vendor end of support; instances whose OS or version has no known date are not assessed.',
            $check->isEncryption() => 'Read from each asset\'s "Encrypted at rest" answer; assets answered "Unknown" are not assessed.',
            default => 'Record the result on each asset\'s (or account\'s) edit form, under Cloud checks.',
        } ?></p>
    </section>
    <?php $cloudTab = ob_get_clean();
}

ob_start(); ?>
<div class="row g-4">
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Risks it mitigates', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore()) . ' ' . Badge::status('risk-status', $r->status, Risk::statusOptions())]) ?></div>
    <div class="col-lg-6"><?= LinkedRecords::widget(['title' => 'Related decisions', 'icon' => 'journal-check', 'models' => $decisions,
        'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?></div>
    <?php foreach (Incident::controlRoleOptions() as $role => $label): ?>
        <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Incidents: ' . lcfirst($label), 'icon' => 'fire', 'models' => $incidents[$role],
            'emptyText' => 'None recorded.',
            'extra' => static fn(Incident $i) => Badge::severity($i->severity)]) ?></div>
    <?php endforeach; ?>
</div>
<p class="gw-muted gw-small">Incident links are recorded on the incident.</p>
<?php $links = ob_get_clean();

ob_start(); ?>
<div class="row g-4">
    <?php foreach ($requirements as $framework => $linked): ?>
        <div class="<?= $frameworkCol ?>"><?= LinkedRecords::widget(['title' => FrameworkCatalogue::shortLabel($framework), 'icon' => FrameworkCatalogue::icon($framework), 'models' => $linked, 'emptyText' => 'Not mapped.']) ?></div>
    <?php endforeach; ?>
    <div class="<?= $frameworkCol ?>">
        <section class="gw-linked">
            <h3 class="gw-linked__title"><i class="bi bi-shield-check" aria-hidden="true"></i> ATT&amp;CK mitigations <span class="gw-linked__count"><?= count($mitigations) ?></span></h3>
            <?= TechniqueTags::widget(['items' => $mitigations, 'emptyText' => 'None mapped.']) ?>
            <?php if ($mitigations): ?>
                <p class="gw-muted gw-small mt-2 mb-0"><?= $model->status === Control::STATUS_IMPLEMENTED && !in_array($model->effectiveness, Control::WEAK_RESULTS, true)
                    ? 'Counted as in place in ATT&CK coverage.'
                    : ($model->status === Control::STATUS_RETIRED || $model->getIsArchived() ? 'Not counted: the control is retired or archived.' : 'Counted as planned until the control is implemented and not found wanting.') ?></p>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php $frameworks = ob_get_clean(); ?>

<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?php if ($weak = $model->weaknesses()): ?>
    <div class="alert alert-warning gw-callout" role="status">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        Risks relying on this control should be reviewed: it is <?= Html::encode(implode(', ', $weak)) ?>.
        <?= $risks ? count($risks) . ' risk(s) link to it.' : '' ?>
    </div>
<?php endif; ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => array_values(array_filter([
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details']],
        ['label' => 'Testing (' . count($tests) . ')', 'content' => $testing, 'options' => ['id' => 'tab-testing'], 'active' => $test->hasErrors()],
        $deviceTab !== null ? ['label' => 'Device check', 'content' => $deviceTab, 'options' => ['id' => 'tab-devices']] : null,
        $cloudTab !== null ? ['label' => 'Cloud check', 'content' => $cloudTab, 'options' => ['id' => 'tab-cloud']] : null,
        ['label' => 'Links (' . (count($risks) + count($decisions) + $incidentCount) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'Frameworks & ATT&CK (' . ($requirementCount + count($mitigations)) . ')', 'content' => $frameworks, 'options' => ['id' => 'tab-frameworks']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'control', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ])),
]) ?>
