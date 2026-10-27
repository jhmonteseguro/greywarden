<?php
/**
 * @var yii\web\View $this
 * @var app\modules\attack\models\AttackMitigation $model
 * @var app\modules\attack\models\AttackTechnique[] $techniques
 * @var app\modules\decision\models\Decision[] $decisions
 * @var app\modules\control\models\Control[] $controls
 * @var array{state:string, controls:array, decisions:array} $adoption
 * @var array<int,int> $observed technique id => incidents
 * @var array<int,int> $modelled technique id => open risks
 * @var array|null $release
 */

use app\helpers\Badge;
use app\modules\attack\components\AttackText;
use app\modules\attack\widgets\KillChain;
use app\modules\attack\widgets\MitigationCoverage;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\control\models\Control;
use app\modules\decision\models\Decision;
use app\widgets\LinkedRecords;
use yii\helpers\Html;

$this->title = $model->name;
$this->params['breadcrumbs'][] = ['label' => 'ATT&CK', 'url' => ['/attack/technique/index']];
$this->params['breadcrumbs'][] = ['label' => 'Mitigations', 'url' => ['index']];
$this->params['breadcrumbs'][] = $model->external_id;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->external_id) . '</span> '
    . MitigationCoverage::stateBadge($adoption['state'])
    . ($model->is_deprecated ? ' ' . Badge::flag('deprecated', 'Deprecated') : '');
$this->params['actions'] = Html::a('<i class="bi bi-box-arrow-up-right"></i> attack.mitre.org', $model->getMitreUrl(), [
    'class' => 'btn btn-outline-secondary gw-btn', 'target' => '_blank', 'rel' => 'noopener noreferrer',
]);

$current = array_values(array_filter($techniques, static fn($t) => !$t->is_deprecated));
$relevant = array_values(array_filter($current, static fn($t) => isset($observed[$t->id]) || isset($modelled[$t->id])));
$deviceChecks = array_values(array_filter($controls, static fn(Control $c) => $c->device_check && !$c->getIsArchived()));
?>
<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Description</h2>
            <div class="gw-attack-text"><?= AttackText::render($model->description) ?: '<p class="gw-null">No description.</p>' ?></div>
            <?php if ($deviceChecks): ?>
                <div class="gw-textblock gw-textblock--highlight">
                    <h3 class="gw-textblock__label">Device checks</h3>
                    <p class="gw-textblock__body mb-0">Checked on devices in the Device Inventory by
                        <?= implode(', ', array_map(static fn(Control $c) => Html::a(Html::encode($c->reference), $c->getViewUrl(), ['class' => 'gw-ref']) . ' ' . Html::encode($c->title), $deviceChecks)) ?>.</p>
                </div>
            <?php endif; ?>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-crosshair" aria-hidden="true"></i> Techniques addressed
                <span class="gw-panel__count"><?= count($current) ?></span></h2>
            <?php if ($relevant): ?>
                <div class="gw-textblock">
                    <h3 class="gw-textblock__label">Seen in your incidents or risks (<?= count($relevant) ?>)</h3>
                    <?= TechniqueTags::widget(['items' => $relevant]) ?>
                </div>
            <?php endif; ?>
            <h3 class="gw-textblock__label">All techniques by tactic</h3>
            <?= KillChain::widget(['techniques' => $current, 'emptyText' => 'MITRE maps no techniques to this mitigation.']) ?>
        </section>
    </div>
    <div class="col-xl-4">
        <?= LinkedRecords::widget(['title' => 'Controls', 'icon' => 'shield-shaded', 'models' => $controls,
            'emptyText' => 'No control in the Control Register implements this mitigation.',
            'extra' => static fn(Control $c) => Badge::status('control-status', $c->status, Control::statusOptions())
                . ' ' . Badge::status('effectiveness', $c->effectiveness, Control::effectivenessOptions())]) ?>
        <?= LinkedRecords::widget(['title' => 'Decisions', 'icon' => 'journal-check', 'models' => $decisions,
            'emptyText' => 'No decision records this mitigation.',
            'extra' => static fn(Decision $d) => Badge::status('decision-status', $d->status, Decision::statusOptions())]) ?>
    </div>
</div>

<?= $this->render('../partials/_attribution', ['release' => $release]) ?>
