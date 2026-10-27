<?php
/**
 * @var yii\web\View $this
 * @var app\modules\attack\models\AttackTechnique $model
 * @var int[] $ids this technique + its sub-techniques
 * @var app\modules\incident\models\Incident[] $incidents
 * @var app\modules\risk\models\Risk[] $risks
 * @var app\modules\inventory\models\Device[] $devices
 * @var array|null $release
 */

use app\helpers\Badge;
use app\modules\attack\components\AttackText;
use app\modules\attack\models\AttackTechnique;
use app\modules\attack\widgets\MitigationCoverage;
use app\modules\attack\widgets\TechniqueTags;
use app\modules\incident\models\Incident;
use app\modules\inventory\models\Device;
use app\modules\risk\models\Risk;
use app\widgets\LinkedRecords;
use yii\helpers\Html;

$this->title = $model->getTitleValue();
$this->params['breadcrumbs'][] = ['label' => 'ATT&CK', 'url' => ['index']];
if ($model->parent) {
    $this->params['breadcrumbs'][] = ['label' => $model->parent->external_id, 'url' => $model->parent->getViewUrl()];
}
$this->params['breadcrumbs'][] = $model->external_id;
$this->params['subtitle'] = '<span class="gw-ref gw-ref--lg">' . Html::encode($model->external_id) . '</span> '
    . implode(' ', array_map(static fn($t) => Html::a(Html::encode($t->name), $t->getViewUrl(), ['class' => 'gw-attack-tactic']), $model->tactics))
    . ($model->is_deprecated ? ' ' . Badge::flag('deprecated', 'Deprecated', $model->revoked_by ? 'Replaced by ' . $model->revoked_by : null) : '');
$this->params['actions'] = Html::a('<i class="bi bi-box-arrow-up-right"></i> attack.mitre.org', $model->getMitreUrl(), [
    'class' => 'btn btn-outline-secondary gw-btn', 'target' => '_blank', 'rel' => 'noopener noreferrer',
]);

// Which mapped technique (this one or a sub-technique) put a record on this page
$via = static function (array $techniques) use ($ids, $model): string {
    $hits = array_filter($techniques, static fn(AttackTechnique $t) => in_array($t->id, $ids, true) && $t->id !== $model->id);
    return $hits ? '<span class="gw-muted">via</span> ' . TechniqueTags::widget(['items' => array_values($hits), 'compact' => true]) : '';
};
$replacement = $model->revoked_by ? AttackTechnique::findByExternalId($model->revoked_by) : null;
?>
<?php if ($model->is_deprecated): ?>
    <div class="alert alert-warning gw-callout" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        MITRE has <?= $model->revoked_by ? 'revoked' : 'deprecated' ?> this technique<?php if ($replacement): ?>; it is replaced by
            <?= TechniqueTags::tag($replacement) ?>. Consider re-mapping the records below<?php endif; ?>.
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Description</h2>
            <div class="gw-attack-text"><?= AttackText::render($model->description) ?: '<p class="gw-null">No description.</p>' ?></div>
        </section>
        <section class="gw-panel">
            <h2 class="gw-panel__title"><i class="bi bi-shield-check" aria-hidden="true"></i> Mitigations</h2>
            <?= MitigationCoverage::widget(['techniqueIds' => [$model->id]]) ?>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Technique</h2>
            <dl class="gw-dl">
                <dt>ID</dt><dd class="gw-mono"><?= Html::encode($model->external_id) ?></dd>
                <dt>Tactics</dt><dd><?= implode(', ', array_map(static fn($t) => Html::a(Html::encode($t->name), $t->getViewUrl()) . ' <span class="gw-muted gw-mono">' . Html::encode($t->external_id) . '</span>', $model->tactics)) ?: '&mdash;' ?></dd>
                <dt>Platforms</dt><dd><?= Html::encode(implode(', ', $model->getPlatformList())) ?: '&mdash;' ?></dd>
                <?php if ($model->parent): ?>
                    <dt>Parent</dt><dd><?= TechniqueTags::tag($model->parent) ?></dd>
                <?php endif; ?>
                <dt>Last modified</dt><dd><?= $model->modified_on ? Yii::$app->formatter->asDate($model->modified_on) : '&mdash;' ?></dd>
            </dl>
            <?php if ($model->subtechniques): ?>
                <h3 class="gw-textblock__label gw-attack-subhead">Sub-techniques (<?= count($model->subtechniques) ?>)</h3>
                <ul class="gw-attack-sublist">
                    <?php foreach ($model->subtechniques as $sub): ?>
                        <li class="<?= $sub->is_deprecated ? 'is-deprecated' : '' ?>"><?= Html::a('<span class="gw-attack-tag__id">' . Html::encode($sub->external_id) . '</span> ' . Html::encode($sub->name), $sub->getViewUrl()) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Observed in incidents', 'icon' => 'fire', 'models' => $incidents,
        'emptyText' => 'Not recorded on any incident.',
        'extra' => static fn(Incident $i) => Badge::severity($i->severity) . ' ' . $via($i->techniques)]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Mapped to risks', 'icon' => 'exclamation-triangle', 'models' => $risks,
        'emptyText' => 'Not mapped to any risk.',
        'extra' => static fn(Risk $r) => Badge::score($r->getCurrentScore()) . ' ' . $via($r->techniques)]) ?></div>
    <div class="col-lg-4"><?= LinkedRecords::widget(['title' => 'Devices affected', 'icon' => 'pc-display', 'models' => $devices,
        'emptyText' => 'No devices linked to those incidents.',
        'extra' => static fn(Device $d) => Badge::criticality($d->criticality)]) ?></div>
</div>

<?= $this->render('../partials/_attribution', ['release' => $release]) ?>
