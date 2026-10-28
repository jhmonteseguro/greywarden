<?php
/**
 * @var yii\web\View $this
 * @var app\modules\control\models\FrameworkRequirement $model
 * @var array $row FrameworkCoverage::rows() entry
 * @var app\modules\control\models\Control[] $controls every linked control, including retired / archived
 */

use app\components\Rbac;
use app\helpers\Badge;
use app\modules\control\components\FrameworkCatalogue;
use app\modules\control\models\Control;
use app\widgets\ActiveForm;
use app\widgets\AuditHistory;
use app\widgets\LinkedRecords;
use yii\helpers\Html;

$this->title = $model->title;
$this->params['breadcrumbs'][] = ['label' => 'Frameworks', 'url' => ['index']];
$this->params['breadcrumbs'][] = ['label' => FrameworkCatalogue::shortLabel($model->framework), 'url' => ['view', 'framework' => FrameworkCatalogue::slug($model->framework)]];
$this->params['breadcrumbs'][] = $model->external_id;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->external_id) . '</span> '
    . Badge::frameworkState($row['state'], $model->partial_coverage ? 'Controls cover only part of this requirement' : null)
    . ($row['unverified'] ? ' ' . Badge::flag('unverified', 'Not tested') : '');
$this->params['actions'] = Rbac::canManage()
    ? Html::a('<i class="bi bi-plus-lg"></i> New control', ['/control/control/create'], ['class' => 'btn btn-outline-secondary gw-btn'])
    : '';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><?= Html::encode(FrameworkCatalogue::label($model->framework)) ?> · <?= Html::encode($model->group_name) ?></h2>
            <?php if ($model->description): ?>
                <div class="gw-textblock gw-textblock--highlight"><div class="gw-textblock__body"><?= Html::encode($model->description) ?></div></div>
            <?php endif; ?>
            <p class="gw-muted gw-small mb-0"><?= match ($model->framework) {
                FrameworkCatalogue::ISO27001 => 'Annex A control title only. See ISO/IEC 27001:2022 for the control and ISO/IEC 27002:2022 for implementation guidance.',
                FrameworkCatalogue::SOC2 => 'Paraphrased. See the AICPA 2017 Trust Services Criteria for the criterion and its points of focus.',
                FrameworkCatalogue::NIST_CSF => 'NIST CSF 2.0 subcategory (outcome). See NIST CSWP 29 and the CSF 2.0 implementation examples for guidance.',
                FrameworkCatalogue::NCSC_CAF => 'CAF 4.0 contributing outcome. Assess it against its indicators of good practice in the NCSC CAF document.',
                default => 'Paraphrased from the NCSC Cyber Essentials requirements for IT infrastructure.',
            } ?></p>
        </section>
        <?= LinkedRecords::widget(['title' => 'Implemented by', 'icon' => 'shield-shaded', 'models' => $controls,
            'emptyText' => 'No control is mapped to this requirement yet. Map one from the control\'s edit form.',
            'extra' => static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions()) . ' ' . Badge::effectiveness($c->effectiveness)]) ?>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title"><?= $model->framework === FrameworkCatalogue::CYBER_ESSENTIALS ? 'Scope' : 'Applicability' ?></h2>
            <?php if (Rbac::canManage()): ?>
                <?php $form = ActiveForm::begin(['id' => 'requirement-form']); ?>
                <?= $form->field($model, 'applicable')->checkbox() ?>
                <?= $form->field($model, 'justification')->textarea(['rows' => 4])
                    ->hint('Why it applies, or why it is excluded. Auditors expect a reason either way.') ?>
                <?= $form->field($model, 'partial_coverage')->checkbox()
                    ->hint('Caps the requirement at partly met, e.g. MFA on some services when the requirement asks for it everywhere.') ?>
                <?= $form->field($model, 'coverage_gap')->textarea(['rows' => 3])
                    ->hint('What is not yet covered.') ?>
                <?= Html::submitButton('Save', ['class' => 'btn btn-primary gw-btn']) ?>
                <?php ActiveForm::end(); ?>
            <?php else: ?>
                <dl class="gw-dl">
                    <dt>Applicable</dt><dd><?= Badge::bool($model->applicable) ?></dd>
                    <dt>Justification</dt><dd><?= $model->justification ? nl2br(Html::encode($model->justification)) : '<span class="gw-null">&mdash;</span>' ?></dd>
                    <?php if ($model->partial_coverage): ?>
                        <dt>Coverage gap</dt><dd><?= nl2br(Html::encode((string) $model->coverage_gap)) ?></dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title">History</h2>
            <?= AuditHistory::widget(['entityType' => 'requirement', 'entityId' => $model->id]) ?>
        </section>
    </div>
</div>
