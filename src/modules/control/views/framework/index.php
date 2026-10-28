<?php
/**
 * @var yii\web\View $this
 * @var array<string,array> $summaries framework => FrameworkCoverage::summary()
 */

use app\components\Rbac;
use app\modules\control\components\FrameworkCatalogue;
use yii\helpers\Html;

$this->title = 'Frameworks';
$this->params['breadcrumbs'][] = ['label' => 'Control Register', 'url' => ['/control/control/index']];
$this->params['breadcrumbs'][] = $this->title;
$this->params['subtitle'] = 'Rolled up from the controls mapped to each requirement in the Control Register.';
$intro = [
    FrameworkCatalogue::ISO27001 => 'Statement of Applicability: every Annex A control, whether it applies and why, and the controls that implement it.',
    FrameworkCatalogue::CYBER_ESSENTIALS => 'Readiness against the five technical control themes, including the device checks an assessor will sample.',
    FrameworkCatalogue::SOC2 => 'Readiness against the Trust Services Criteria: Security, plus the categories chosen for the report.',
    FrameworkCatalogue::NIST_CSF => 'Current profile against the CSF 2.0 outcomes across Govern, Identify, Protect, Detect, Respond and Recover.',
    FrameworkCatalogue::NCSC_CAF => 'The 41 contributing outcomes of the Cyber Assessment Framework, across its four objectives and 14 principles.',
];
?>
<?php if (!$summaries): ?>
    <section class="gw-panel">
        <p class="gw-empty">Every compliance framework is switched off.<?= Rbac::can(Rbac::PERM_SETTINGS) ? ' ' . Html::a('Switch one on in Settings.', ['/admin/settings/index', '#' => 'compliance']) : '' ?></p>
    </section>
<?php endif; ?>
<div class="row g-4">
    <?php foreach ($summaries as $framework => $s): ?>
        <div class="col-xl-6">
            <section class="gw-panel gw-framework-card">
                <header class="gw-panel__header">
                    <h2 class="gw-panel__title"><i class="bi bi-<?= Html::encode(FrameworkCatalogue::icon($framework)) ?>" aria-hidden="true"></i> <?= Html::encode(FrameworkCatalogue::label($framework)) ?></h2>
                    <div class="gw-panel__tools"><?= Html::a('Open', ['view', 'framework' => FrameworkCatalogue::slug($framework)], ['class' => 'gw-panel__link']) ?></div>
                </header>
                <p class="gw-muted"><?= Html::encode($intro[$framework] ?? '') ?></p>
                <?= $this->render('_progress', ['framework' => $framework, 'summary' => $s]) ?>
                <p class="gw-muted gw-small mb-0"><?= Html::encode(FrameworkCatalogue::version($framework)) ?></p>
            </section>
        </div>
    <?php endforeach; ?>
</div>
