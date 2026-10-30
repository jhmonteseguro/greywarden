<?php
/**
 * @var yii\web\View $this
 * @var app\modules\supplier\models\Supplier $model
 * @var app\modules\supplier\models\SupplierAssurance $assurance form model for "Add assurance"
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\models\Attachment;
use app\models\RecordNote;
use app\models\RecordReview;
use app\modules\cloud\models\CloudAccount;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\modules\incident\models\Incident;
use app\modules\inventory\components\DevicePosture;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\modules\supplier\models\Supplier;
use app\modules\supplier\models\SupplierAssurance;
use app\widgets\ActiveForm;
use app\widgets\Attachments;
use app\widgets\AuditHistory;
use app\widgets\DetailView;
use app\widgets\LinkedRecords;
use app\widgets\Notes;
use app\widgets\RecordActions;
use app\widgets\Reviews;
use yii\bootstrap5\Tabs;
use yii\helpers\Html;

$fmt = Yii::$app->formatter;
$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'Supplier Register', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->reference;
$posture = $model->getPosture();
$assuranceDue = $model->getAssuranceDue();
$flags = [];
if ($model->isReviewOverdue()) {
    $flags[] = Badge::flag('overdue', 'Review overdue', 'Was due ' . $fmt->asDate($model->next_review_date));
}
if ($model->isRenewalDue()) {
    $flags[] = $model->getNoticeDeadline() < date('Y-m-d')
        ? Badge::flag('overdue', 'Notice deadline passed', 'Notice was due by ' . $fmt->asDate($model->getNoticeDeadline()) . '; renews ' . $fmt->asDate($model->contract_end))
        : Badge::flag('due-soon', 'Notice period closing', 'Give notice by ' . $fmt->asDate($model->getNoticeDeadline()));
}
foreach ($assuranceDue as $a) {
    $flags[] = Badge::flag($a->isExpired() ? 'expired' : 'expiring', $a->isExpired() ? 'Assurance expired' : 'Assurance expiring',
        $a->getTypeLabel() . ' · ' . $fmt->asDate($a->expires_on));
}
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->reference) . '</span> '
    . Badge::status('supplier-status', $model->status, Supplier::statusOptions()) . ' '
    . Badge::tier($model->tier, $model->getTierExplanation()) . ' '
    . Badge::posture($posture['state']) . ' ' . implode(' ', $flags);
$this->params['actions'] = RecordActions::widget(['model' => $model, 'pdf' => true]);
$canEdit = Rbac::canManage() && !$model->getIsArchived();

$accounts = $model->cloudAccounts;
$risks = $model->risks;
$incidents = $model->incidents;
$decisions = $model->decisions;
$controls = $model->controls;
$assurances = $model->assurances;
$reviews = RecordReview::listFor($model);
$resultIcons = [DevicePosture::RESULT_PASS => ['is-ok', 'check-circle-fill'], DevicePosture::RESULT_FAIL => ['is-fail', 'x-circle-fill'], DevicePosture::RESULT_UNKNOWN => ['is-unknown', 'question-circle']];
$unknown = '<span class="gw-null">Unknown</span>';

ob_start(); ?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Supplier</h2>
            <div class="row">
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'reference', 'contentOptions' => ['class' => 'gw-mono']],
                            'name',
                            'legal_name',
                            ['attribute' => 'company_number', 'contentOptions' => ['class' => 'gw-mono']],
                            ['attribute' => 'website', 'format' => 'raw', 'value' => $model->website ? Html::a(Html::encode($model->website), $model->website, ['target' => '_blank', 'rel' => 'noopener noreferrer']) : null],
                            ['attribute' => 'category_id', 'value' => $model->category->name ?? null],
                            ['attribute' => 'owner_id', 'value' => $model->owner->full_name ?? null],
                        ],
                    ]) ?>
                </div>
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'criticality', 'format' => 'raw', 'value' => Badge::criticality($model->criticality)],
                            ['attribute' => 'data_classification', 'format' => 'raw', 'value' => Badge::render('classification', $model->data_classification, Device::classificationOptions()[$model->data_classification] ?? null)],
                            ['attribute' => 'personal_data', 'format' => 'raw', 'value' => Badge::bool($model->personal_data)],
                            ['attribute' => 'access_level', 'format' => 'raw', 'value' => Badge::render('access', $model->access_level, $model->getAccessLabel())],
                            'data_locations',
                        ],
                    ]) ?>
                </div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'What they provide', 'text' => $model->services]) ?>
        </section>
        <section class="gw-panel gw-supplier-contract">
            <h2 class="gw-panel__title">Contract &amp; exit</h2>
            <div class="row">
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'contract_start', 'format' => 'date'],
                            ['attribute' => 'contract_end', 'format' => 'raw', 'value' => $model->contract_end ? $fmt->asDate($model->contract_end) : null],
                            ['attribute' => 'notice_days', 'format' => 'raw', 'value' => $model->notice_days !== null
                                ? Html::encode($model->notice_days . ' days') . ($model->contract_end ? '<div class="gw-muted gw-small">Give notice by ' . $fmt->asDate($model->getNoticeDeadline()) . '</div>' : '')
                                : null],
                        ],
                    ]) ?>
                </div>
                <div class="col-lg-6">
                    <?= DetailView::widget([
                        'model' => $model,
                        'attributes' => [
                            ['attribute' => 'dpa_status', 'format' => 'raw', 'value' => $model->dpa_status
                                ? Badge::render('dpa', $model->dpa_status, Supplier::dpaOptions()[$model->dpa_status] ?? null)
                                : ($model->personal_data ? $unknown : '<span class="gw-muted">Not needed: no personal data</span>')],
                            ['attribute' => 'breach_notice_hours', 'value' => $model->breach_notice_hours !== null ? 'Within ' . $model->breach_notice_hours . ' hours' : null],
                            ['attribute' => 'right_to_audit', 'format' => 'raw', 'value' => $model->right_to_audit ? Html::encode(Supplier::yesNoOptions()[$model->right_to_audit]) : $unknown],
                            'security_contact',
                            ['attribute' => 'status_page_url', 'format' => 'raw', 'value' => $model->status_page_url ? Html::a(Html::encode($model->status_page_url), $model->status_page_url, ['target' => '_blank', 'rel' => 'noopener noreferrer']) : null],
                        ],
                    ]) ?>
                </div>
            </div>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Exit plan', 'text' => $model->exit_plan]) ?>
            <?= $this->render('@app/views/partials/_text-block', ['label' => 'Notes', 'text' => $model->notes]) ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel gw-supplier-tier">
            <h2 class="gw-panel__title">Tier</h2>
            <p class="mb-2"><?= Badge::tier($model->tier) ?> <strong><?= Html::encode(Supplier::tierOptions()[$model->tier] ?? '') ?></strong></p>
            <p class="gw-muted gw-small"><?= Html::encode(ucfirst(explode(': ', $model->getTierExplanation(), 2)[1])) ?>.</p>
            <dl class="gw-dl">
                <dt>Reviewed every</dt><dd><?= Supplier::reviewMonths($model->tier) ?> months</dd>
                <dt>Next review</dt>
                <dd><?= $model->next_review_date ? $fmt->asDate($model->next_review_date) . ($model->isReviewOverdue() ? ' ' . Badge::flag('overdue', 'Overdue') : '') : '<span class="gw-null">Not set</span>' ?></dd>
            </dl>
        </section>
        <section class="gw-panel gw-posture gw-posture--<?= Html::encode($posture['state']) ?>">
            <h2 class="gw-panel__title">Supplier checks</h2>
            <div class="gw-posture__status"><?= Badge::posture($posture['state']) ?></div>
            <?php if (!$posture['items']): ?>
                <p class="gw-empty">No supplier check in force applies to this supplier.</p>
            <?php else: ?>
            <ul class="gw-checklist">
                <?php foreach ($posture['items'] as $id => $item): [$cls, $icon] = $resultIcons[$item['result']]; ?>
                    <li class="gw-checklist__item <?= $cls ?>">
                        <i class="bi bi-<?= $icon ?>" aria-hidden="true" title="<?= Html::encode(DevicePosture::resultLabels()[$item['result']]) ?>"></i>
                        <span><?= Html::a(Html::encode($item['check']->title), ['/control/control/view', 'id' => $id], ['class' => 'gw-checklist__link']) ?>
                            <?php if ($item['detail']): ?><span class="gw-muted">· <?= Html::encode($item['detail']) ?></span>
                            <?php elseif ($item['result'] === DevicePosture::RESULT_UNKNOWN): ?><span class="gw-muted">· Not assessed</span><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
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
            <h2 class="gw-panel__title">Assurance on file <span class="gw-panel__count"><?= count($assurances) ?></span></h2>
            <?php if (!$assurances): ?>
                <p class="gw-empty">No assurance recorded yet. Add certificates (ISO 27001, Cyber Essentials), audit reports (SOC 2), pen test summaries or questionnaires as you receive them.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table gw-coverage__table gw-supplier-assurance">
                        <thead><tr><th scope="col">Type</th><th scope="col">Details</th><th scope="col">Issued</th><th scope="col">Expires</th><?= $canEdit ? '<th scope="col"><span class="visually-hidden">Actions</span></th>' : '' ?></tr></thead>
                        <tbody>
                        <?php foreach ($assurances as $a): ?>
                            <tr>
                                <td><?= Html::encode($a->getTypeLabel()) ?></td>
                                <td>
                                    <?= $a->description ? Html::encode($a->description) : '' ?>
                                    <?= $a->evidence ? '<div class="gw-muted gw-small">' . Html::encode($a->evidence) . '</div>' : '' ?>
                                    <div class="gw-muted gw-small">Recorded <?= $fmt->asDate($a->created_at) ?></div>
                                </td>
                                <td class="gw-mono"><?= $a->issued_on ? $fmt->asDate($a->issued_on) : '<span class="gw-null">&mdash;</span>' ?></td>
                                <td class="gw-mono">
                                    <?= $a->expires_on ? $fmt->asDate($a->expires_on) : '<span class="gw-null">No expiry</span>' ?>
                                    <?= $a->isExpired() ? ' ' . Badge::flag('expired', 'Expired') : ($a->expiresSoon() ? ' ' . Badge::flag('expiring', 'Expiring') : '') ?>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end"><?= Html::a('<i class="bi bi-trash" aria-hidden="true"></i>', ['delete-assurance', 'id' => $model->id, 'assurance' => $a->id], [
                                        'class' => 'gw-icon-btn', 'title' => 'Remove this assurance', 'aria-label' => 'Remove this assurance',
                                        'data-method' => 'post', 'data-confirm' => 'Remove this assurance? The supplier\'s history keeps a note of it.',
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
            <h2 class="gw-panel__title"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add assurance</h2>
            <?php $form = ActiveForm::begin(['id' => 'supplier-assurance-form', 'action' => ['add-assurance', 'id' => $model->id]]); ?>
            <?= $form->errorSummary($assurance, ['class' => 'alert alert-danger gw-error-summary']) ?>
            <?= $form->field($assurance, 'type')->dropDownList(SupplierAssurance::typeOptions(), ['prompt' => '— Select —']) ?>
            <?= $form->field($assurance, 'description')->textInput(['maxlength' => true]) ?>
            <div class="row">
                <div class="col-sm-6"><?= $form->field($assurance, 'issued_on')->input('date', ['max' => date('Y-m-d')]) ?></div>
                <div class="col-sm-6"><?= $form->field($assurance, 'expires_on', ['enableClientValidation' => false])->input('date') ?></div>
            </div>
            <?= $form->field($assurance, 'evidence')->textInput(['maxlength' => true]) ?>
            <?= Html::submitButton('Add assurance', ['class' => 'btn btn-primary gw-btn']) ?>
            <?php ActiveForm::end(); ?>
        </section>
    </div>
    <?php endif; ?>
</div>
<?php $assuranceTab = ob_get_clean();

ob_start(); ?>
<div class="row g-4">
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Cloud accounts', 'icon' => 'cloud-check', 'models' => $accounts,
        'emptyText' => 'None linked. Pick this supplier on a cloud account\'s edit form.',
        'extra' => static fn(CloudAccount $a) => '<span class="gw-muted">' . Html::encode($a->getProviderLabel()) . '</span> ' . Badge::posture($a->getPosture()['state'])]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related risks', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore()) . ' ' . Badge::status('risk-status', $r->status, Risk::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Incidents involving them', 'icon' => 'fire', 'models' => $incidents,
        'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . Badge::status('incident-status', $i->status, Incident::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Related decisions', 'icon' => 'journal-check', 'models' => $decisions,
        'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Controls that rely on them', 'icon' => 'shield-shaded', 'models' => $controls,
        'extra' => static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions())]) ?></div>
</div>
<p class="gw-muted gw-small">Link risks, incidents, decisions and controls here from the supplier's edit form, or from their own edit forms.</p>
<?php $links = ob_get_clean();

$attachments = Attachment::listFor($model);
$notes = RecordNote::listFor($model); ?>
<?= $this->render('@app/views/partials/_archived-notice', ['model' => $model]) ?>

<?= Tabs::widget([
    'options' => ['class' => 'gw-tabs'],
    'tabContentOptions' => ['class' => 'gw-tab-content'],
    'items' => [
        ['label' => 'Details', 'content' => $details, 'options' => ['id' => 'tab-details'], 'active' => !$assurance->hasErrors()],
        ['label' => 'Assurance (' . count($assurances) . ')', 'content' => $assuranceTab, 'options' => ['id' => 'tab-assurance'], 'active' => $assurance->hasErrors()],
        ['label' => 'Reviews (' . count($reviews) . ')', 'content' => Reviews::widget(['model' => $model, 'reviews' => $reviews]), 'options' => ['id' => 'tab-reviews']],
        ['label' => 'Notes (' . count($notes) . ')', 'content' => Notes::widget(['model' => $model, 'notes' => $notes, 'attachments' => $attachments]), 'options' => ['id' => 'tab-notes']],
        ['label' => 'Links (' . (count($accounts) + count($risks) + count($incidents) + count($decisions) + count($controls)) . ')', 'content' => $links, 'options' => ['id' => 'tab-links']],
        ['label' => 'Files (' . count($attachments) . ')', 'content' => Attachments::widget(['model' => $model, 'attachments' => $attachments]), 'options' => ['id' => 'tab-files']],
        ['label' => 'History', 'content' => AuditHistory::widget(['entityType' => 'supplier', 'entityId' => $model->id]), 'options' => ['id' => 'tab-history']],
    ],
]) ?>
